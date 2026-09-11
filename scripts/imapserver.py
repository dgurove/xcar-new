"""Крошечный IMAP-сервер для проверки почты xcar-new: письма в памяти,
новые подкладываются файлами в imapdrop/<user>/*.eml (раз в секунду)."""
import email, io, os, time, glob, sys
from email.utils import parsedate_to_datetime
from zope.interface import implementer
from twisted.cred import checkers, portal
from twisted.internet import reactor, task, protocol
from twisted.mail import imap4
from twisted.python import log

DROP = '/tmp/claude-501/imapdrop'
USERS = {b'offer': b'parol', b'deal': b'parol'}

@implementer(imap4.IMessage)
class Msg:
    def __init__(self, uid, raw, flags=None, date=None):
        self.uid = uid; self.raw = raw; self.flags = set(flags or []); self.date = date or time.strftime('%d-%b-%Y %H:%M:%S +0000', time.gmtime())
        self.msg = email.message_from_bytes(raw)
    def getUID(self): return self.uid
    def getFlags(self): return list(self.flags)
    def getInternalDate(self): return self.date
    def getHeaders(self, negate, *names):
        h = {}
        for k, v in self.msg.items():
            if (k.lower() in [n.lower() for n in names]) != negate or not names:
                h[k] = v
        return h
    def getBodyFile(self):
        return io.BytesIO(self.raw.split(b'\r\n\r\n', 1)[1] if b'\r\n\r\n' in self.raw else self.raw.split(b'\n\n', 1)[1] if b'\n\n' in self.raw else b'')
    def getSize(self): return len(self.raw)
    def isMultipart(self): return False
    def getSubPart(self, part): raise IndexError

@implementer(imap4.IMailbox)
class Box:
    def __init__(self, name):
        self.name = name; self.messages = []; self.next_uid = 1; self.listeners = []; self.validity = 12345
    def getUIDValidity(self): return self.validity
    def getUIDNext(self): return self.next_uid
    def getUID(self, seq): return self.messages[seq - 1].uid
    def getMessageCount(self): return len(self.messages)
    def getRecentCount(self): return 0
    def getUnseenCount(self): return len([m for m in self.messages if '\\Seen' not in m.flags])
    def isWriteable(self): return True
    def destroy(self): pass
    def requestStatus(self, names): return imap4.statusRequestHelper(self, names)
    def addListener(self, l): self.listeners.append(l)
    def removeListener(self, l):
        if l in self.listeners: self.listeners.remove(l)
    def getFlags(self):
        role = {'Sent': ['\\Sent'], 'Trash': ['\\Trash'], 'Spam': ['\\Junk'], 'Drafts': ['\\Drafts']}.get(self.name, [])
        return ['\\Seen', '\\Answered', '\\Flagged', '\\Deleted', '\\Draft'] + role
    def getHierarchicalDelimiter(self): return '/'
    def addMessage(self, message, flags=(), date=None):
        raw = message.read() if hasattr(message, 'read') else message
        m = Msg(self.next_uid, raw, [f.decode() if isinstance(f, bytes) else f for f in flags], date.decode() if isinstance(date, bytes) else date)
        self.next_uid += 1; self.messages.append(m)
        for l in self.listeners: l.newMessages(len(self.messages), 0)
        print(f'[{self.name}] APPEND uid={m.uid} bytes={len(raw)}', flush=True)
        from twisted.internet import defer
        return defer.succeed(m.uid)
    def expunge(self):
        gone = [i + 1 for i, m in enumerate(self.messages) if '\\Deleted' in m.flags]
        self.messages = [m for m in self.messages if '\\Deleted' not in m.flags]
        return gone
    def _resolve(self, messages, uid):
        if not self.messages: return []
        if uid:
            messages.last = max([m.uid for m in self.messages] or [0])
            return [(i + 1, m) for i, m in enumerate(self.messages) if m.uid in messages]
        messages.last = len(self.messages)
        return [(i, self.messages[i - 1]) for i in messages if 0 < i <= len(self.messages)]
    def fetch(self, messages, uid):
        return self._resolve(messages, uid)
    def store(self, messages, flags, mode, uid):
        out = {}
        for seq, m in self._resolve(messages, uid):
            fl = [f.decode() if isinstance(f, bytes) else f for f in flags]
            if mode == 0: m.flags = set(fl)
            elif mode > 0: m.flags |= set(fl)
            else: m.flags -= set(fl)
            out[seq] = list(m.flags)
        return out

@implementer(imap4.IAccount)
class Account:
    def __init__(self, user):
        self.user = user
        self.boxes = {'INBOX': Box('INBOX'), 'Sent': Box('Sent'), 'Trash': Box('Trash'), 'Spam': Box('Spam'), 'Drafts': Box('Drafts')}
    def addMailbox(self, name, mbox=None): self.boxes[name] = mbox or Box(name); return True
    def create(self, path): return self.addMailbox(path)
    def select(self, name, rw=True): return self.boxes.get(name if name != 'INBOX' else 'INBOX') or self.boxes.get(name.upper() if name.upper() == 'INBOX' else name)
    def delete(self, name): self.boxes.pop(name, None)
    def rename(self, old, new): self.boxes[new] = self.boxes.pop(old)
    def isSubscribed(self, name): return True
    def subscribe(self, name): pass
    def unsubscribe(self, name): pass
    def listMailboxes(self, ref, wildcard): return list(self.boxes.items())

ACCOUNTS = {}
@implementer(portal.IRealm)
class Realm:
    def requestAvatar(self, avatarId, mind, *interfaces):
        user = avatarId.decode() if isinstance(avatarId, bytes) else avatarId
        ACCOUNTS.setdefault(user, Account(user))
        return imap4.IAccount, ACCOUNTS[user], lambda: None

class Server(imap4.IMAP4Server):
    def __init__(self, *a, **kw):
        super().__init__(*a, **kw)
        self.challengers = {b'LOGIN': imap4.LOGINCredentials, b'PLAIN': imap4.PLAINCredentials}
        self.portal = PORTAL
    # APPENDUID как у UIDPLUS
    def _cbAppend(self, result, tag, mbox):
        self.sendPositiveResponse(tag, f'[APPENDUID {mbox.getUIDValidity()} {result}] APPEND complete'.encode())
    def do_APPEND(self, tag, mailbox, flags, date, message):
        mailbox = self.account.select(mailbox.decode() if isinstance(mailbox, bytes) else mailbox)
        if mailbox is None:
            self.sendNegativeResponse(tag, b'[TRYCREATE] No such mailbox'); return
        mailbox.addMessage(message, flags, date).addCallback(self._cbAppend, tag, mailbox)
    arg_append_ = imap4.IMAP4Server.arg_append_ if hasattr(imap4.IMAP4Server, 'arg_append_') else None
    def do_CAPABILITY(self, tag):
        self.sendUntaggedResponse(b'CAPABILITY IMAP4rev1 IDLE UIDPLUS LIST-STATUS AUTH=PLAIN AUTH=LOGIN')
        self.sendPositiveResponse(tag, b'CAPABILITY completed')

PORTAL = portal.Portal(Realm())
PORTAL.registerChecker(checkers.InMemoryUsernamePasswordDatabaseDontUse(**{k.decode(): v for k, v in USERS.items()}))

class Factory(protocol.Factory):
    def buildProtocol(self, addr):
        p = Server(); p.factory = self; return p

def drop():
    for path in sorted(glob.glob(f'{DROP}/*/*.eml')):
        user = os.path.basename(os.path.dirname(path))
        ACCOUNTS.setdefault(user, Account(user))
        raw = open(path, 'rb').read().replace(b'\r\n', b'\n').replace(b'\n', b'\r\n')
        ACCOUNTS[user].boxes['INBOX'].addMessage(raw)
        os.remove(path)

log.startLogging(sys.stdout)
task.LoopingCall(drop).start(1)
reactor.listenTCP(1143, Factory(), interface='127.0.0.1')
print('IMAP на 127.0.0.1:1143', flush=True)
reactor.run()
