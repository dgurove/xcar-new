// xcar-whatsapp — связанное устройство WhatsApp номера владельца для xcar (03.11.2026, ../README.md).
//
// Умеет одно: отправить сообщение в одну группу, зашитую в настройках (TARGET). Что и когда слать, решает xcar на
// проде (App\WhatsApp\Group) и приходит сюда через туннель xcar-tunnel (на проде zurich:9444 → 127.0.0.1:9480).
// Сессия — SQLite в ~/.local/share/xcar-whatsapp, PDF только в памяти.
//
// Чтобы номер не сочли ботом: в «Связанных устройствах» — Chrome на Linux, историю переписки не качаем, «прочитано» не
// ставим, «в сети» — только на время отправки, перед ней «печатает…». Отвязали, забанили, перехватили сессию — служба
// останавливается, пишет владельцу в Telegram и сама не переподключается (снять — README).
package main

import (
	"bytes"
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
	listen = "127.0.0.1:9480"
	// Как назовётся устройство в «Связанных устройствах».
	deviceName = "Chrome (Linux)"
	// Состав группы спрашиваем у WhatsApp не чаще.
	groupTTL = 10 * time.Minute
	// Владельцу в Telegram — не чаще.
	alertEvery = 30 * time.Minute
)

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

// open — клиент на сессии из SQLite и своя таблица отправленного (ключ xcar → id сообщения).
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
	if _, err := db.ExecContext(ctx, `create table if not exists xcar_sent (key text primary key, id text not null, done integer not null default 0, at integer not null)`); err != nil {
		return nil, nil, err
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
	tell(fmt.Sprintf("Код для WhatsApp: %s\n\nНа телефоне: WhatsApp → Настройки → Связанные устройства → Привязка устройства → «Связать по номеру телефона» и ввести код. Жду 10 минут.", code), false)
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
	s := &service{cli: cli, db: db, token: token}
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
	mux.HandleFunc("POST /send", s.auth(s.send))
	srv := &http.Server{Addr: listen, Handler: mux, ReadTimeout: 2 * time.Minute, WriteTimeout: 3 * time.Minute}
	go func() {
		<-ctx.Done()
		shut, done := context.WithTimeout(context.Background(), 10*time.Second)
		defer done()
		_ = srv.Shutdown(shut)
	}()
	log.Printf("слушаю %s, группа %s", listen, s.target)
	if err := srv.ListenAndServe(); !errors.Is(err, http.ErrServerClosed) {
		return err
	}
	return nil
}

// event — то, после чего слать нельзя: служба встаёт до человека.
func (s *service) event(evt any) {
	switch e := evt.(type) {
	case *events.LoggedOut:
		s.stop(fmt.Sprintf("устройство отвязано от WhatsApp (%s)", e.Reason))
	case *events.TemporaryBan:
		s.stop("WhatsApp временно заблокировал номер: " + e.String())
	case *events.StreamReplaced:
		s.stop("сессию перехватил другой клиент с теми же ключами")
	case *events.ClientOutdated:
		s.stop("WhatsApp считает клиент устаревшим — обновить whatsmeow")
	case *events.ConnectFailure:
		log.Printf("WhatsApp отказал в подключении: %s %s", e.Reason, e.Message)
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
	tell("xcar WhatsApp остановлен: "+reason+"\n\nПредложения в группу не уходят. Снять — zurich/README.md.", true)
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
		"in_group": false, "group": nil, "me": nil, "members": []string{},
	}
	if stopped != "" {
		out["stopped"] = stopped
	}
	if s.cli.Store.ID != nil {
		out["me"] = s.cli.Store.ID.User
	}
	if info, err := s.groupInfo(r.Context(), false); err == nil && info != nil {
		out["in_group"], out["group"], out["members"] = s.member(info), info.Name, members(info)
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

// send — одно сообщение в группу: PDF с подписью (или текст). Ключ xcar помнится вместе с id сообщения: повтор того же
// ключа идёт с тем же id, и WhatsApp второй раз его не покажет.
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
	pdf, thumb := formFile(r, "pdf"), formFile(r, "thumb")

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
	if info, err := s.groupInfo(ctx, false); err != nil || info == nil || !s.member(info) {
		reply(w, http.StatusConflict, map[string]string{"error": "номер не в группе"})
		return
	}
	var id string
	var done bool
	err := s.db.QueryRowContext(ctx, `select id, done from xcar_sent where key = ?`, key).Scan(&id, &done)
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

	s.typing(ctx)
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
	_ = s.cli.SendChatPresence(ctx, s.target, types.ChatPresencePaused, types.ChatPresenceMediaText)
	if _, err := s.cli.SendMessage(ctx, s.target, msg, whatsmeow.SendRequestExtra{ID: id}); err != nil {
		reply(w, http.StatusBadGateway, map[string]string{"error": "отправка: " + err.Error()})
		return
	}
	_, _ = s.db.ExecContext(context.Background(), `update xcar_sent set done = 1 where key = ?`, key)
	s.later()
	reply(w, http.StatusOK, map[string]string{"id": id})
}

// typing — «в сети» и 2–5 секунд «печатает…», как человек перед пересылкой.
func (s *service) typing(ctx context.Context) {
	if s.away != nil {
		s.away.Stop()
	}
	if err := s.cli.SendPresence(ctx, types.PresenceAvailable); err != nil {
		log.Printf("«в сети» не встало: %v", err)
	}
	_ = s.cli.SendChatPresence(ctx, s.target, types.ChatPresenceComposing, types.ChatPresenceMediaText)
	time.Sleep(time.Duration(2000+rand.IntN(3000)) * time.Millisecond)
}

// later — через 20–40 секунд «не в сети», если за это время не пришло следующее.
func (s *service) later() {
	s.away = time.AfterFunc(time.Duration(20+rand.IntN(20))*time.Second, func() {
		_ = s.cli.SendPresence(context.Background(), types.PresenceUnavailable)
	})
}

func (s *service) groupInfo(ctx context.Context, fresh bool) (*types.GroupInfo, error) {
	if s.target.IsEmpty() {
		return nil, errors.New("TARGET не задан")
	}
	s.groupMu.Lock()
	defer s.groupMu.Unlock()
	if !fresh && s.group != nil && time.Since(s.groupAt) < groupTTL {
		return s.group, nil
	}
	if !s.cli.IsLoggedIn() {
		return nil, errors.New("не на связи")
	}
	info, err := s.cli.GetGroupInfo(ctx, s.target)
	if err != nil {
		s.group = nil
		return nil, err
	}
	s.group, s.groupAt = info, time.Now()
	return info, nil
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

// members — номера участников цифрами; кого WhatsApp не раскрыл — JID как есть (xcar сочтёт его посторонним).
func members(info *types.GroupInfo) []string {
	out := make([]string, 0, len(info.Participants))
	for _, p := range info.Participants {
		switch {
		case !p.PhoneNumber.IsEmpty():
			out = append(out, p.PhoneNumber.User)
		case p.JID.Server == types.DefaultUserServer:
			out = append(out, p.JID.User)
		default:
			out = append(out, p.JID.String())
		}
	}
	return out
}

type upload struct{ data []byte }

func formFile(r *http.Request, name string) *upload {
	f, _, err := r.FormFile(name)
	if err != nil {
		return nil
	}
	defer f.Close()
	data, err := io.ReadAll(f)
	if err != nil || len(data) == 0 {
		return nil
	}
	return &upload{data: data}
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

// tell — владельцу в Telegram через ~/bin/tg-send (бот ccgram). limited — не чаще раза в 30 минут.
func tell(text string, limited bool) {
	stamp := filepath.Join(stateDir, "alert.stamp")
	if limited {
		if fi, err := os.Stat(stamp); err == nil && time.Since(fi.ModTime()) < alertEvery {
			return
		}
		_ = os.MkdirAll(stateDir, 0o700)
		_ = os.WriteFile(stamp, nil, 0o600)
	}
	cmd := exec.Command(filepath.Join(home, "bin/tg-send"))
	cmd.Stdin = strings.NewReader(text)
	if out, err := cmd.CombinedOutput(); err != nil {
		log.Printf("Telegram не ушёл: %v %s", err, out)
	}
}
