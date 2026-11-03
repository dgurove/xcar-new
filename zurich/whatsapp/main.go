// xcar-whatsapp — связанное устройство WhatsApp номера владельца для xcar (03.11.2026, ../README.md).
//
// Шлёт сообщение в одну группу, зашитую в настройках (TARGET), или в личные тому, кто написал первым (POST /send, поле
// to). Что, кому и когда, решает xcar на проде (App\WhatsApp) и приходит сюда через туннель xcar-tunnel (на проде
// zurich:9444 → 127.0.0.1:9480).
//
// Входящие: менеджер отправляет из xcar по ссылке wa.me «Подключить XCar: K7P2QM4D» — личное сообщение с кодом ложится в
// xcar_inbox, отправитель — в xcar_allowed (только им можно в личные), xcar забирает строки длинным опросом GET /inbox и
// привязывает профиль. Остальные входящие — мимо: ни в базу, ни в журнал, это личный WhatsApp владельца.
// Сессия и таблицы xcar_* — SQLite в ~/.local/share/xcar-whatsapp, PDF только в памяти.
//
// Чтобы номер не сочли ботом: в «Связанных устройствах» — Chrome на Linux, историю переписки не качаем, «прочитано» —
// только у сообщений с кодом, «в сети» — только на время отправки, перед ней «печатает…» в чате получателя. Отвязали,
// забанили, перехватили сессию — служба останавливается, пишет владельцу в Telegram и сама не переподключается (снять —
// README).
package main

import (
	"bytes"
	"cmp"
	"context"
	"crypto/subtle"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"image"
	_ "image/jpeg"
	"io"
	"log"
	"math/rand/v2"
	"net/http"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	_ "github.com/mattn/go-sqlite3"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/proto/waCompanionReg"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/store"
	"go.mau.fi/whatsmeow/store/sqlstore"
	"go.mau.fi/whatsmeow/types"
	"go.mau.fi/whatsmeow/types/events"
	waLog "go.mau.fi/whatsmeow/util/log"
	"google.golang.org/protobuf/proto"
)

const (
	// LISTEN в окружении — другой адрес (проверочная копия).
	listen = "127.0.0.1:9480"
	// Как назовётся устройство в «Связанных устройствах».
	deviceName = "Chrome (Linux)"
	// Состав группы спрашиваем у WhatsApp не чаще; ссылку-приглашение, которую не дали, — тоже.
	groupTTL = 10 * time.Minute
	// Сколько ждать ответа WhatsApp про группу и ссылку вместе: xcar ждёт /status 15 с.
	groupWait = 10 * time.Second
	// Ссылку-приглашение группы спрашиваем не чаще.
	inviteTTL = time.Hour
	// Дольше /inbox не ждёт.
	inboxWait = 50 * time.Second
	// Входящие с кодом храним столько.
	inboxKeep = 7 * 24 * time.Hour
)

// Код привязки из ссылки xcar: «Подключить XCar: K7P2QM4D».
var codeRe = regexp.MustCompile(`(?i)xcar\s*:\s*([0-9A-Z]{8})\b`)

var (
	home, _  = os.UserHomeDir()
	dataDir  = filepath.Join(home, ".local/share/xcar-whatsapp")
	stateDir = filepath.Join(home, ".local/state/xcar-whatsapp")
	stopFile = filepath.Join(stateDir, "stopped")
)

func main() {
	log.SetFlags(0)
	if len(os.Args) < 2 {
		fmt.Fprintln(os.Stderr, "xcar-whatsapp pair <номер> | serve")
		os.Exit(2)
	}
	var err error
	switch os.Args[1] {
	case "pair":
		if len(os.Args) != 3 {
			err = errors.New("нужен номер: xcar-whatsapp pair 79001234567")
		} else {
			err = pair(os.Args[2])
		}
	case "serve":
		err = serve()
	default:
		err = fmt.Errorf("нет команды %q", os.Args[1])
	}
	if err != nil {
		log.Fatal(err)
	}
}

// open — клиент на сессии из SQLite и свои таблицы: отправленное (ключ xcar → id сообщения), входящие с кодом и кому
// можно в личные.
func open(ctx context.Context) (*whatsmeow.Client, *sql.DB, error) {
	if err := os.MkdirAll(dataDir, 0o700); err != nil {
		return nil, nil, err
	}
	path := filepath.Join(dataDir, "session.db")
	db, err := sql.Open("sqlite3", "file:"+path+"?_foreign_keys=on&_busy_timeout=5000")
	if err != nil {
		return nil, nil, err
	}
	container := sqlstore.NewWithDB(db, "sqlite3", waLog.Stdout("store", "WARN", false))
	if err := container.Upgrade(ctx); err != nil {
		return nil, nil, err
	}
	_ = os.Chmod(path, 0o600)
	for _, q := range []string{
		`create table if not exists xcar_sent (key text primary key, id text not null, done integer not null default 0, at integer not null)`,
		`create table if not exists xcar_inbox (id integer primary key autoincrement, jid text not null, phone text not null, name text not null, text text not null, at integer not null)`,
		`create table if not exists xcar_allowed (jid text primary key, at integer not null)`,
	} {
		if _, err := db.ExecContext(ctx, q); err != nil {
			return nil, nil, err
		}
	}
	device, err := container.GetFirstDevice(ctx)
	if err != nil {
		return nil, nil, err
	}
	store.SetOSInfo("Linux", [3]uint32{6, 8, 0})
	store.DeviceProps.PlatformType = waCompanionReg.DeviceProps_CHROME.Enum()
	cli := whatsmeow.NewClient(device, waLog.Stdout("whatsapp", "WARN", false))
	// Историю переписки телефон предлагает сам — не берём.
	cli.ManualHistorySyncDownload = true

	return cli, db, nil
}

// pair — привязка кодом из 8 знаков: код уходит владельцу в Telegram, он вводит его в WhatsApp на телефоне. Без канала QR:
// тот рвёт соединение через ~2,5 минуты, а человеку на код нужно больше — ждём до 10 минут.
func pair(phone string) error {
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
	defer cancel()
	cli, db, err := open(ctx)
	if err != nil {
		return err
	}
	defer db.Close()
	if cli.Store.ID != nil {
		return fmt.Errorf("уже привязано к %s; чтобы привязать заново, удалите %s", cli.Store.ID.User, dataDir)
	}
	ready, paired := make(chan struct{}, 1), make(chan error, 1)
	cli.AddEventHandler(func(evt any) {
		switch e := evt.(type) {
		case *events.QR:
			select {
			case ready <- struct{}{}:
			default:
			}
		case *events.PairSuccess:
			paired <- nil
		case *events.PairError:
			paired <- e.Error
		}
	})
	if err := cli.Connect(); err != nil {
		return err
	}
	defer cli.Disconnect()
	select {
	case <-ready:
	case <-ctx.Done():
		return errors.New("WhatsApp не предложил привязку")
	}
	code, err := cli.PairPhone(ctx, phone, true, whatsmeow.PairClientChrome, deviceName)
	if err != nil {
		return err
	}
	log.Printf("код: %s", code)
	tell(fmt.Sprintf("Код для WhatsApp: %s\n\nНа телефоне: WhatsApp → Настройки → Связанные устройства → Привязка устройства → «Связать по номеру телефона» и ввести код. Жду 10 минут.", code))
	select {
	case err := <-paired:
		if err != nil {
			return fmt.Errorf("привязка не удалась: %w", err)
		}
	case <-ctx.Done():
		return errors.New("код не ввели за 10 минут")
	}
	log.Printf("привязано: %s", cli.Store.ID)
	// Дать телефону передать ключи и имя профиля, иначе «в сети» потом не поставить.
	time.Sleep(30 * time.Second)
	return nil
}

type service struct {
	cli    *whatsmeow.Client
	db     *sql.DB
	token  string
	target types.JID

	sendMu sync.Mutex // одна отправка за раз
	away   *time.Timer

	stateMu sync.Mutex
	stopped string

	groupMu sync.Mutex
	group   *types.GroupInfo
	groupAt time.Time

	inviteMu   sync.Mutex
	invite     string
	inviteNext time.Time

	inboxMu sync.Mutex
	inboxCh chan struct{} // закрывается, когда пришло входящее с кодом: будит ждущих /inbox
	quit    <-chan struct{}
}

func serve() error {
	ctx, cancel := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer cancel()
	token := os.Getenv("TOKEN")
	if len(token) < 16 {
		return errors.New("TOKEN не задан или короче 16 знаков (~/.config/xcar-whatsapp/env)")
	}
	cli, db, err := open(ctx)
	if err != nil {
		return err
	}
	defer db.Close()
	s := &service{cli: cli, db: db, token: token, inboxCh: make(chan struct{}), quit: ctx.Done()}
	if raw := strings.TrimSpace(os.Getenv("TARGET")); raw != "" {
		if s.target, err = types.ParseJID(raw); err != nil || s.target.Server != types.GroupServer {
			return fmt.Errorf("TARGET %q — не JID группы", raw)
		}
	}
	if b, err := os.ReadFile(stopFile); err == nil {
		s.stopped = strings.TrimSpace(string(b))
	}
	cli.AddEventHandler(s.event)
	switch {
	case s.stopped != "":
		log.Printf("остановлена: %s — не подключаюсь", s.stopped)
	case cli.Store.ID == nil:
		log.Printf("не привязана: xcar-whatsapp pair <номер>")
	default:
		if err := cli.Connect(); err != nil {
			return err
		}
	}
	defer cli.Disconnect()

	mux := http.NewServeMux()
	mux.HandleFunc("GET /status", s.auth(s.status))
	mux.HandleFunc("GET /groups", s.auth(s.groups))
	mux.HandleFunc("GET /inbox", s.auth(s.inbox))
	mux.HandleFunc("POST /send", s.auth(s.send))
	addr := cmp.Or(os.Getenv("LISTEN"), listen)
	srv := &http.Server{Addr: addr, Handler: mux, ReadTimeout: 2 * time.Minute, WriteTimeout: 3 * time.Minute}
	go func() {
		<-ctx.Done()
		shut, done := context.WithTimeout(context.Background(), 10*time.Second)
		defer done()
		_ = srv.Shutdown(shut)
	}()
	log.Printf("слушаю %s, группа %s", addr, s.target)
	if err := srv.ListenAndServe(); !errors.Is(err, http.ErrServerClosed) {
		return err
	}
	return nil
}

// event — входящие с кодом и то, после чего слать нельзя: служба встаёт до человека.
func (s *service) event(evt any) {
	switch e := evt.(type) {
	case *events.Message:
		s.incoming(e)
	case *events.LoggedOut:
		s.stop(fmt.Sprintf("устройство отвязано от WhatsApp (%s)", e.Reason))
	case *events.TemporaryBan:
		s.stop("WhatsApp временно заблокировал номер: " + e.String())
	case *events.StreamReplaced:
		s.stop("сессию перехватил другой клиент с теми же ключами")
	case *events.ClientOutdated:
		s.stop("WhatsApp считает клиент устаревшим — обновить whatsmeow")
	case *events.ConnectFailure:
		// Сюда whatsmeow отдаёт только отказы, после которых сам не переподключается (временные — переподключает молча).
		s.stop(fmt.Sprintf("WhatsApp отказал в подключении: %s %s", e.Reason, e.Message))
	case *events.CATRefreshError:
		s.stop("WhatsApp не продлил доступ: " + e.Error.Error())
	case *events.Connected:
		log.Printf("на связи")
	}
}

func (s *service) stop(reason string) {
	s.stateMu.Lock()
	first := s.stopped == ""
	s.stopped = reason
	s.stateMu.Unlock()
	if !first {
		return
	}
	log.Printf("остановка: %s", reason)
	_ = os.MkdirAll(stateDir, 0o700)
	_ = os.WriteFile(stopFile, []byte(reason+"\n"), 0o600)
	go s.cli.Disconnect()
	// Остановка — раз на состояние (`first`, файл stopped): повторяться нечему, а снятую и снова случившуюся надо знать.
	tell("xcar WhatsApp остановлен: " + reason + "\n\nПредложения в WhatsApp не уходят. Снять — zurich/README.md.")
}

// incoming — личное сообщение с кодом xcar: отправитель — в xcar_allowed (раньше строки: увидел её xcar — уже можно
// писать), строка — в xcar_inbox, сообщение — «прочитано». Остальное молча мимо.
func (s *service) incoming(e *events.Message) {
	chat := e.Info.Chat.ToNonAD()
	if e.Info.IsFromMe || e.Info.IsGroup || (chat.Server != types.DefaultUserServer && chat.Server != types.HiddenUserServer) {
		return
	}
	text := e.Message.GetConversation()
	if text == "" {
		text = e.Message.GetExtendedTextMessage().GetText()
	}
	if !codeRe.MatchString(text) {
		return
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	// Номер и LID отправителя, что из них известно: второй — из SenderAlt, иначе из таблицы соответствий whatsmeow.
	var pn, lid types.JID
	alt := e.Info.SenderAlt.ToNonAD()
	if chat.Server == types.HiddenUserServer {
		lid = chat
		if alt.Server == types.DefaultUserServer {
			pn = alt
		} else if j, err := s.cli.Store.LIDs.GetPNForLID(ctx, lid); err == nil {
			pn = j.ToNonAD()
		}
	} else {
		pn = chat
		if alt.Server == types.HiddenUserServer {
			lid = alt
		} else if j, err := s.cli.Store.LIDs.GetLIDForPN(ctx, pn); err == nil {
			lid = j.ToNonAD()
		}
	}
	jid := lid
	if !pn.IsEmpty() {
		jid = pn
	}
	now := time.Now()
	at := e.Info.Timestamp
	if at.IsZero() {
		at = now
	}
	for _, j := range []types.JID{pn, lid} {
		if j.IsEmpty() {
			continue
		}
		if _, err := s.db.ExecContext(ctx, `insert into xcar_allowed (jid, at) values (?, ?) on conflict (jid) do update set at = excluded.at`, j.String(), now.Unix()); err != nil {
			log.Printf("входящее с кодом: xcar_allowed не записан: %v", err)
			return
		}
	}
	if _, err := s.db.ExecContext(ctx, `insert into xcar_inbox (jid, phone, name, text, at) values (?, ?, ?, ?, ?)`, jid.String(), pn.User, e.Info.PushName, text, at.Unix()); err != nil {
		log.Printf("входящее с кодом: xcar_inbox не записан: %v", err)
		return
	}
	_, _ = s.db.ExecContext(ctx, `delete from xcar_inbox where at < ?`, now.Add(-inboxKeep).Unix())
	s.wake()
	// Не в обработчике: MarkRead может сначала спросить у WhatsApp настройки приватности.
	go func(info types.MessageInfo) {
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer cancel()
		if err := s.cli.MarkRead(ctx, []types.MessageID{info.ID}, time.Now(), info.Chat, info.Sender); err != nil {
			log.Printf("«прочитано» не встало: %v", err)
		}
	}(e.Info)
}

// wake — будит всех, кто ждёт /inbox.
func (s *service) wake() {
	s.inboxMu.Lock()
	defer s.inboxMu.Unlock()
	close(s.inboxCh)
	s.inboxCh = make(chan struct{})
}

func (s *service) inboxSignal() <-chan struct{} {
	s.inboxMu.Lock()
	defer s.inboxMu.Unlock()
	return s.inboxCh
}

func (s *service) auth(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if subtle.ConstantTimeCompare([]byte(r.Header.Get("Authorization")), []byte("Bearer "+s.token)) != 1 {
			reply(w, http.StatusUnauthorized, map[string]string{"error": "нет ключа"})
			return
		}
		next(w, r)
	}
}

func (s *service) stopReason() string {
	s.stateMu.Lock()
	defer s.stateMu.Unlock()
	return s.stopped
}

func (s *service) status(w http.ResponseWriter, r *http.Request) {
	stopped := s.stopReason()
	out := map[string]any{
		"connected": s.cli.IsConnected(), "logged_in": s.cli.IsLoggedIn(), "stopped": nil,
		"in_group": nil, "group": nil, "invite": nil,
	}
	if stopped != "" {
		out["stopped"] = stopped
	}
	ctx, cancel := context.WithTimeout(r.Context(), groupWait)
	defer cancel()
	// in_group: true/false — знаем; null — WhatsApp не ответил, а прежнего ответа нет (не путать с «не в группе»).
	switch info, err := s.groupInfo(ctx); {
	case info != nil:
		in := s.member(info)
		out["in_group"], out["group"] = in, info.Name
		if in {
			out["invite"] = s.inviteLink(ctx)
		}
	case errors.Is(err, whatsmeow.ErrNotInGroup) || errors.Is(err, whatsmeow.ErrGroupNotFound):
		out["in_group"] = false
	case err != nil:
		out["group_error"] = err.Error()
	}
	reply(w, http.StatusOK, out)
}

// groups — где состоит номер: чтобы найти JID группы для TARGET.
func (s *service) groups(w http.ResponseWriter, r *http.Request) {
	if !s.cli.IsLoggedIn() {
		reply(w, http.StatusServiceUnavailable, map[string]string{"error": "не на связи"})
		return
	}
	list, err := s.cli.GetJoinedGroups(r.Context())
	if err != nil {
		reply(w, http.StatusBadGateway, map[string]string{"error": err.Error()})
		return
	}
	out := []map[string]any{}
	for _, g := range list {
		out = append(out, map[string]any{"jid": g.JID.String(), "name": g.Name, "members": len(g.Participants)})
	}
	reply(w, http.StatusOK, out)
}

type inboxRow struct {
	ID    int64  `json:"id"`
	JID   string `json:"jid"`
	Phone string `json:"phone"`
	Name  string `json:"name"`
	Text  string `json:"text"`
	At    int64  `json:"at"`
}

// inbox — входящие с кодом после after, по возрастанию, не больше 100. Нет — ждёт новое до wait секунд (до 50): будит
// обработчик событий, базу по кругу не спрашиваем. Работает и у остановленной службы.
func (s *service) inbox(w http.ResponseWriter, r *http.Request) {
	after, _ := strconv.ParseInt(r.URL.Query().Get("after"), 10, 64)
	// after больше любого выданного id — базу завели заново (перепривязка), xcar этого не видит: отдаём с начала.
	// Выданное — по sqlite_sequence, а не по строкам: после чистки старых таблица бывает пустой.
	var last int64
	if err := s.db.QueryRowContext(r.Context(), `select coalesce((select seq from sqlite_sequence where name = 'xcar_inbox'), 0)`).Scan(&last); err != nil {
		reply(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
		return
	}
	if after > last {
		after = 0
	}
	secs, _ := strconv.Atoi(r.URL.Query().Get("wait"))
	wait := min(time.Duration(max(secs, 0))*time.Second, inboxWait)
	deadline := time.NewTimer(wait)
	defer deadline.Stop()
	for {
		// Сигнал — до запроса: строка, записанная после него, всё равно разбудит.
		signal := s.inboxSignal()
		out, err := s.inboxAfter(r.Context(), after)
		if err != nil {
			reply(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
			return
		}
		if len(out) > 0 || wait == 0 {
			reply(w, http.StatusOK, out)
			return
		}
		select {
		case <-signal:
		case <-deadline.C:
			wait = 0
		case <-s.quit:
			wait = 0
		case <-r.Context().Done():
			return
		}
	}
}

func (s *service) inboxAfter(ctx context.Context, after int64) ([]inboxRow, error) {
	rows, err := s.db.QueryContext(ctx, `select id, jid, phone, name, text, at from xcar_inbox where id > ? order by id limit 100`, after)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []inboxRow{}
	for rows.Next() {
		var row inboxRow
		if err := rows.Scan(&row.ID, &row.JID, &row.Phone, &row.Name, &row.Text, &row.At); err != nil {
			return nil, err
		}
		out = append(out, row)
	}
	return out, rows.Err()
}

// allowed — писал ли этот JID первым (прислал код).
func (s *service) allowed(ctx context.Context, jid types.JID) (bool, error) {
	err := s.db.QueryRowContext(ctx, `select 1 from xcar_allowed where jid = ?`, jid.String()).Scan(new(int))
	if errors.Is(err, sql.ErrNoRows) {
		return false, nil
	}
	return err == nil, err
}

// send — одно сообщение в группу TARGET или, с полем to, в личные тому, кто писал первым: PDF с подписью (или текст).
// Ключ xcar помнится вместе с id сообщения: повтор того же ключа идёт с тем же id, и WhatsApp второй раз его не покажет.
func (s *service) send(w http.ResponseWriter, r *http.Request) {
	if err := r.ParseMultipartForm(32 << 20); err != nil {
		reply(w, http.StatusBadRequest, map[string]string{"error": "не multipart: " + err.Error()})
		return
	}
	key, caption := r.FormValue("key"), r.FormValue("caption")
	if key == "" || caption == "" {
		reply(w, http.StatusBadRequest, map[string]string{"error": "нужны key и caption"})
		return
	}
	pdf, err := formFile(r, "pdf")
	if err != nil {
		reply(w, http.StatusBadRequest, map[string]string{"error": "PDF не прочитан: " + err.Error()})
		return
	}
	thumb, _ := formFile(r, "thumb")
	chat := s.target
	if to := strings.TrimSpace(r.FormValue("to")); to != "" {
		jid, err := types.ParseJID(to)
		if err != nil || jid.User == "" || (jid.Server != types.DefaultUserServer && jid.Server != types.HiddenUserServer) {
			reply(w, http.StatusBadRequest, map[string]string{"error": "to — не личный JID"})
			return
		}
		chat = jid.ToNonAD()
		switch ok, err := s.allowed(r.Context(), chat); {
		case err != nil:
			reply(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
			return
		case !ok:
			reply(w, http.StatusForbidden, map[string]string{"error": "не писал первым"})
			return
		}
	}

	s.sendMu.Lock()
	defer s.sendMu.Unlock()
	// Оборвался запрос xcar — начатая отправка всё равно доходит до конца: повтор придёт с тем же ключом.
	ctx, cancel := context.WithTimeout(context.WithoutCancel(r.Context()), 100*time.Second)
	defer cancel()
	if stopped := s.stopReason(); stopped != "" {
		reply(w, http.StatusServiceUnavailable, map[string]string{"error": "остановлена: " + stopped})
		return
	}
	if !s.cli.IsConnected() || !s.cli.IsLoggedIn() {
		reply(w, http.StatusServiceUnavailable, map[string]string{"error": "нет связи с WhatsApp"})
		return
	}
	if chat == s.target {
		info, err := s.groupInfo(ctx)
		switch {
		case info != nil && !s.member(info), errors.Is(err, whatsmeow.ErrNotInGroup), errors.Is(err, whatsmeow.ErrGroupNotFound):
			reply(w, http.StatusConflict, map[string]string{"error": "номер не в группе"})
			return
		case info == nil:
			reply(w, http.StatusServiceUnavailable, map[string]string{"error": "не узнать группу: " + fmt.Sprint(err)})
			return
		}
	}
	var id string
	var done bool
	err = s.db.QueryRowContext(ctx, `select id, done from xcar_sent where key = ?`, key).Scan(&id, &done)
	switch {
	case err == nil && done:
		reply(w, http.StatusOK, map[string]string{"id": id})
		return
	case errors.Is(err, sql.ErrNoRows):
		id = s.cli.GenerateMessageID()
		if _, err := s.db.ExecContext(ctx, `insert into xcar_sent (key, id, at) values (?, ?, ?)`, key, id, time.Now().Unix()); err != nil {
			reply(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
			return
		}
	case err != nil:
		reply(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
		return
	}

	s.typing(ctx, chat)
	msg := &waE2E.Message{Conversation: proto.String(caption)}
	if pdf != nil {
		up, err := s.cli.Upload(ctx, pdf.data, whatsmeow.MediaDocument)
		if err != nil {
			reply(w, http.StatusBadGateway, map[string]string{"error": "загрузка PDF: " + err.Error()})
			return
		}
		name := r.FormValue("filename")
		if name == "" {
			name = "offer.pdf"
		}
		doc := &waE2E.DocumentMessage{
			URL: proto.String(up.URL), DirectPath: proto.String(up.DirectPath), MediaKey: up.MediaKey,
			FileEncSHA256: up.FileEncSHA256, FileSHA256: up.FileSHA256, FileLength: proto.Uint64(up.FileLength),
			Mimetype: proto.String("application/pdf"), FileName: proto.String(name),
			Title: proto.String(strings.TrimSuffix(name, ".pdf")), Caption: proto.String(caption),
			MediaKeyTimestamp: proto.Int64(time.Now().Unix()),
		}
		if n := pages(pdf.data); n > 0 {
			doc.PageCount = proto.Uint32(n)
		}
		if thumb != nil {
			if cfg, _, err := image.DecodeConfig(bytes.NewReader(thumb.data)); err == nil {
				doc.JPEGThumbnail = thumb.data
				doc.ThumbnailWidth, doc.ThumbnailHeight = proto.Uint32(uint32(cfg.Width)), proto.Uint32(uint32(cfg.Height))
			}
		}
		msg = &waE2E.Message{DocumentMessage: doc}
	}
	_ = s.cli.SendChatPresence(ctx, chat, types.ChatPresencePaused, types.ChatPresenceMediaText)
	if _, err := s.cli.SendMessage(ctx, chat, msg, whatsmeow.SendRequestExtra{ID: id}); err != nil {
		reply(w, http.StatusBadGateway, map[string]string{"error": "отправка: " + err.Error()})
		return
	}
	_, _ = s.db.ExecContext(context.Background(), `update xcar_sent set done = 1 where key = ?`, key)
	s.later()
	reply(w, http.StatusOK, map[string]string{"id": id})
}

// typing — «в сети» и 2–5 секунд «печатает…» в чате получателя, как человек перед пересылкой.
func (s *service) typing(ctx context.Context, chat types.JID) {
	if s.away != nil {
		s.away.Stop()
	}
	if err := s.cli.SendPresence(ctx, types.PresenceAvailable); err != nil {
		log.Printf("«в сети» не встало: %v", err)
	}
	_ = s.cli.SendChatPresence(ctx, chat, types.ChatPresenceComposing, types.ChatPresenceMediaText)
	time.Sleep(time.Duration(2000+rand.IntN(3000)) * time.Millisecond)
}

// later — через 20–40 секунд «не в сети», если за это время не пришло следующее.
func (s *service) later() {
	s.away = time.AfterFunc(time.Duration(20+rand.IntN(20))*time.Second, func() {
		_ = s.cli.SendPresence(context.Background(), types.PresenceUnavailable)
	})
}

// groupInfo — группа TARGET: из памяти до 10 минут, иначе у WhatsApp. Не ответил — прежний ответ, если он есть: короткий
// сбой связи не превращается в «не в группе». Вышли из группы — забываем сразу.
func (s *service) groupInfo(ctx context.Context) (*types.GroupInfo, error) {
	if s.target.IsEmpty() {
		return nil, errors.New("TARGET не задан")
	}
	s.groupMu.Lock()
	defer s.groupMu.Unlock()
	if s.group != nil && time.Since(s.groupAt) < groupTTL {
		return s.group, nil
	}
	if !s.cli.IsLoggedIn() {
		return s.group, errors.New("не на связи")
	}
	ask, cancel := context.WithTimeout(ctx, groupWait)
	defer cancel()
	info, err := s.cli.GetGroupInfo(ask, s.target)
	switch {
	case errors.Is(err, whatsmeow.ErrNotInGroup) || errors.Is(err, whatsmeow.ErrGroupNotFound):
		s.group = nil
		return nil, err
	case err != nil:
		log.Printf("группа не ответила: %v", err)
		return s.group, err
	}
	s.group, s.groupAt = info, time.Now()
	return info, nil
}

// inviteLink — ссылка-приглашение группы TARGET: из памяти до часа. Не дали (номер не админ группы, сбой) — прежняя или
// null, и снова спросим не раньше чем через 10 минут: xcar зовёт /status часто.
func (s *service) inviteLink(ctx context.Context) any {
	s.inviteMu.Lock()
	defer s.inviteMu.Unlock()
	if time.Now().After(s.inviteNext) && s.cli.IsLoggedIn() {
		link, err := s.cli.GetGroupInviteLink(ctx, s.target, false)
		if err != nil {
			log.Printf("ссылку на группу не дали: %v", err)
			s.inviteNext = time.Now().Add(groupTTL)
		} else {
			s.invite, s.inviteNext = link, time.Now().Add(inviteTTL)
		}
	}
	if s.invite == "" {
		return nil
	}
	return s.invite
}

// member — состоит ли сам номер в группе (по номеру или LID).
func (s *service) member(info *types.GroupInfo) bool {
	if s.cli.Store.ID == nil {
		return false
	}
	me, lid := s.cli.Store.ID.User, s.cli.Store.GetLID().User
	for _, p := range info.Participants {
		if p.JID.User == me || p.PhoneNumber.User == me || (lid != "" && (p.LID.User == lid || p.JID.User == lid)) {
			return true
		}
	}
	return false
}

type upload struct{ data []byte }

// formFile — часть запроса; нет части — nil без ошибки, есть, но не прочиталась или пустая — ошибка.
func formFile(r *http.Request, name string) (*upload, error) {
	f, _, err := r.FormFile(name)
	if errors.Is(err, http.ErrMissingFile) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	defer f.Close()
	data, err := io.ReadAll(f)
	if err == nil && len(data) == 0 {
		err = errors.New("пустой файл")
	}
	if err != nil {
		return nil, err
	}
	return &upload{data: data}, nil
}

var pageRe = regexp.MustCompile(`/Type\s*/Page[^s]`)

// pages — сколько страниц в PDF, если их видно без разбора потоков; 0 — не знаем.
func pages(pdf []byte) uint32 {
	return uint32(len(pageRe.FindAllIndex(pdf, -1)))
}

func reply(w http.ResponseWriter, code int, body any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(body)
}

// tell — владельцу в Telegram через ~/bin/tg-send (бот ccgram).
func tell(text string) {
	cmd := exec.Command(filepath.Join(home, "bin/tg-send"))
	cmd.Stdin = strings.NewReader(text)
	if out, err := cmd.CombinedOutput(); err != nil {
		log.Printf("Telegram не ушёл: %v %s", err, out)
	}
}
