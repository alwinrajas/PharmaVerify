# Installing PharmaVerify

This guide is for the person installing PharmaVerify on the pharmacy computer.
You do not need to be a developer, and you will not need to type any commands.

If you are IT staff and want the technical detail, read
[`ADMIN-GUIDE.md`](ADMIN-GUIDE.md) instead.

---

## What you received

| File | What it is |
|---|---|
| `PharmaVerify-Setup-v1.0.3.exe` | The installer. Run this on the pharmacy PC. |
| `PharmaVerify-HHT.apk` | The app for the handheld scanners. |
| This guide | What you are reading. |

---

## Before you begin

You need three things ready.

**1. The pharmacy computer.** An ordinary Windows PC in the back office. It must
stay switched on while staff are counting stock, because it is where the counts
are stored.

**2. SQL Server Express.** This is Microsoft's free database. If it is not
already on the PC, download and install it first — the PharmaVerify installer
will tell you if it is missing. Accept the default options.

**3. The store Wi-Fi.** The PC and the handheld scanners must be on the same
network. Not a guest network.

> **Ask your IT contact for one thing before you start:** a fixed address for
> this PC on the network. Without it the PC's address can change on its own —
> usually overnight — and every handheld stops working until it is set up again.

---

## Installing

**Step 1.** Right-click `PharmaVerify-Setup-v1.0.3.exe` and choose
**Run as administrator**. Windows will ask for permission; say yes.

**Step 2 — Welcome.** Click **Next**.

**Step 3 — System check.** The installer looks at the PC and tells you what it
found.

- Lines marked `[ok]` are fine.
- Lines marked `[--]` will be set up for you.
- Lines marked `[!]` need attention. The most common is SQL Server not being
  installed — stop here, install it, and run the installer again.

**Step 4 — Where to install.** The default is correct. Click **Next**.

**Step 5 — Database.** Choose a password. It must be at least 12 characters.

> You will never have to type this password again — PharmaVerify remembers it.
> Write it down and give it to whoever looks after the PC, then keep it
> somewhere safe. It is not the password anyone signs in with.

**Step 6 — Network.** The installer suggests `pharmaverify.local`. Leave it
unless your IT contact has told you otherwise. Click **Next**.

**Step 7 — Ready.** You will see a summary of what is about to happen. Click
**Install**.

**Step 8 — Wait.** This takes a few minutes. The installer is setting up the
database, the web server and the firewall. Do not close it.

**Step 9 — Finished.** Tick **Open PharmaVerify now** and click **Finish**.

PharmaVerify opens in your browser.

---

## First sign-in

Sign in with:

| | |
|---|---|
| Email | `admin@pharmaverify.com` |
| Password | `Pharma@2026` |

**Change this password immediately.** It is printed in this guide, which means
it is not a secret. Then create a proper account for each person who will use
the system.

---

## Setting up a handheld scanner

Do this once per scanner.

**On the PC**, in PharmaVerify:

1. Open **HHT Devices**.
2. Add the scanner. Give it the code printed on the scanner itself — `HHT-01`,
   `HHT-02` — and choose which shop it belongs to.
3. Click the **pair** button on that row. A code appears.

**The code is shown once and cannot be looked up again.** If you lose it, just
generate another.

**On the scanner:**

4. Install `PharmaVerify-HHT.apk`.
5. Open **Settings**.
6. Type the server address exactly as the installer showed it, for example
   `http://pharmaverify.local:8000`.
7. Type the pairing code and tap **Pair**.

The scanner should now show:

```
✓ Paired with PharmaVerify
HHT-01 · P001 — SHOP ONE

🟢 Connected to PharmaVerify
Last checked 09:14
```

> **"Paired" and "Connected" mean different things.** Paired means the scanner
> is registered. Connected means it can reach the PC right now. A scanner can be
> paired but not connected — for example when it is out of Wi-Fi range — and
> that is normal. Counts are kept safely on the scanner and sent automatically
> when it reconnects.

---

## Counting stock

1. On the scanner, start a count and scan the shelf.
2. When finished, tap **Submit to PharmaVerify**.
3. The scanner shows **Sending to PharmaVerify…**, then:

```
✓ Received by PharmaVerify
HHT-01 · P001
Audit AUD-30082026-0001
24 stock records received
```

4. On the PC, open **Audits**. The count appears on its own within about fifteen
   seconds. You do not need to refresh, and you do not need to import any file.

That reference number — `AUD-30082026-0001` — is how the office identifies that
count. It is the same on the scanner and on the PC.

---

## If something is wrong

Open **Start menu → PharmaVerify → PharmaVerify Health**. It checks everything
and tells you in plain language what is not working.

For anything it does not resolve, see [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md).

---

## Everyday running

**Backups.** Open **Start menu → PharmaVerify → Back Up Now** before anything
significant, and regularly otherwise. This PC is usually the only place the
stock counts exist. If the disk fails and there is no backup, the only way to
recover is to count everything again.

**Restarting the PC.** PharmaVerify starts again by itself. Nobody needs to
launch anything.

**Opening PharmaVerify.** Use the desktop shortcut, or open the address in any
browser on the store network.

---

## Removing PharmaVerify

Uninstall it from Windows Settings, as with any program.

**Your data is not deleted.** Stock counts, audits and settings are kept, and
reinstalling finds them again. Removing the database is a separate, deliberate
step your IT contact would have to take.
