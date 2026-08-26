# 14 — Client Demonstration Guide

A 12–15 minute walkthrough that tells one continuous story: a shop counts its
stock, the count disagrees with the system, and the business puts it right.

Do not open screens at random. Follow the flow — the point is that each step
leads to the next.

---

## Before the room

```bash
# Backend
cd backend
php artisan migrate:fresh --seed        # ~15 seconds
php artisan serve

# Frontend
cd frontend
npm run dev
```

| Check | |
| --- | --- |
| ☐ | Both servers running; the sign-in screen loads |
| ☐ | Signed in once already, so the first click is instant |
| ☐ | `database/sample-data/stock-phm001-valid.xlsx` to hand for the import step |
| ☐ | `ONEDRIVE_DEMO_FAIL_RATE=0` in `.env` so the upload succeeds |
| ☐ | Browser zoom around 90% so a full table fits |
| ☐ | Notifications and other tabs closed |

**Accounts** — password `Pharma@2026` for all three:

| Role | Email |
| --- | --- |
| Administrator | `admin@pharmaverify.com` |
| Supervisor | `supervisor@pharmaverify.com` |
| Shop User | `annanagar@pharmaverify.com` |

---

## Opening (30 seconds)

> “This is PharmaVerify. Your shops count their stock on handheld devices. This
> application holds what the system says each shop has, receives what was
> actually found on the shelf, shows you exactly where the two disagree, and
> lets an authorised person put the system right — with a full record of who
> changed what.”

---

## 1 · Sign in — 30s

Sign in as **Administrator**.

> “Access is by permission. What each person can see and do is decided on the
> server, so what appears on screen is never the whole control.”

## 2 · Dashboard — 1m

> “The three figures that matter are here: lines still to verify, lines where the
> count disagrees with the system, and differences not yet applied to stock.”

Point at the variance breakdown.

> “Short in red, excess in green, matched in grey. That is the day's work at a
> glance.”

## 3 · Master — 1m 30s

**Master → Shops.** Three branches, each with its own stock, devices and audits.

**Master → Items.** Real pharmacy products.

**Master → HHT Devices.** Pause here and read the banner aloud:

> “A count is identified by **shop, device and audit number together**. Several
> devices in one shop are often on the same audit number at once, and they drift
> out of step — device one on audit three while device three is still on two.
> Anywhere you see an audit number in this application, its shop and device are
> beside it, because the number alone means nothing.”

That single point is the architecture of the whole system. Do not rush it.

## 4 · Stock Import — 2m

**Master → Item Stock Import.** Note the current record count for the shop first.

Choose the file and click **Import Stock**. The shop selector is optional — the
report names its own branches — so leave it on *All shops named in the file*.
Read the confirmation aloud:

> “This is the rule the business asked for. A stock file does not add to what the
> shop has — it *replaces* it. The file is the truth.”

Confirm. Show the summary: total, imported, failed, previous records replaced.

> “Two hundred and thirty-six previous records removed, two hundred and forty
> imported. And it is all-or-nothing — if the file cannot be processed, nothing
> changes and the shop keeps exactly what it had.”

If any rows failed, click the red count.

> “Every rejected row, with the column, the value and what was wrong with it. The
> user knows precisely what to fix.”

**Stock Verification → Item Stock** — the shop now holds what the file said.

## 5 · The count arrives — 2m

**Stock Verification → HHT Submissions → HHT Simulator.**

> “The Android application isn't part of this two-day build, so this stands in
> for it — same endpoint, same contract, same behaviour.”

Choose the shop, choose device **HHT-03**, set audit number **4**, click **Load 8
products**, then **Apply a realistic variance spread**.

> “The device holds every scan in its own storage while counting. There is no
> synchronisation as they go — the shops don't have reliable signal, and a count
> only means something once it's finished.”

Click **Share / Submit**.

> “That's the one moment anything is sent. The whole count, in a single call.”

### The idempotency moment — do not skip this

Click **Share / Submit** again.

> “Signal drops, the device retries — this is what happens.”

*Duplicate submission ignored.*

> “One audit, not two. The count is identified by shop, device, audit number and
> a submission id the device generates, so a retry is recognised and safely
> ignored. This is the difference between a system you can trust after a bad day
> in the field and one you can't.”

## 6 · The audit — 1m 30s

Click **Open audit** in the banner.

Walk the header: audit number, shop, device, who counted, when.

Then the summary strip:

> “Eight lines counted. Two short, two over, four agreed. Net variance minus
> four.”

Then the table:

> “System quantity, physical quantity, variance — side by side, per line. Red
> short, green over, grey agreed.”

## 7 · Verification — 1m 30s

Click the pencil on a short line.

> “Editing a completed count is allowed — a recount happens. But only for people
> with the permission.”

Change the physical quantity and watch the variance update live. Add a remark:
*Recounted with the shelf supervisor.* **Save and verify.**

**Administration → Activity Log.**

> “There it is: who, when, which field, old value, new value. Corrections are
> expected. What matters is that they're traceable.”

## 8 · Variance — 1m

**Stock Verification → Variance.**

> “Physical minus system, across every count. System says a hundred, shelf has
> ninety-five, variance minus five.”

Show the summary strip, then filter to **Short (negative)**.

> “Shop, device, audit number, date, direction — filter to exactly what you want
> to act on.”

## 9 · Adjustment — 1m 30s

Before clicking, open Item Stock in a second tab and note the quantity — or read
it aloud from the variance row.

Click **Adjust** on the −5 line. Read the dialog:

> “A hundred and twenty-five, becoming a hundred and twenty. And this posts
> immediately — no approval screen, no supervisor step. That's what the business
> asked for, and that's what it does.”

Add a reason. **Post adjustment.**

Go to **Item Stock** and show the new quantity.

> “Already changed. No queue, no pending state.”

**Stock Verification → Stock Adjustment.**

> “And the history: what changed, from what to what, by whom, when and why.”

## 10 · Stock Take — 1m

**Stock Verification → Stock Take.** Point at the highlighted panel.

> “Sometimes the shelf holds something the stock file knows nothing about. That's
> not a variance — there's nothing to compare it against.”

Click **Record** on a candidate, then save. Read the confirmation aloud:

> “‘The item master has not been changed.’ That was explicit in the requirement —
> the application records what was found, but whether that product joins the item
> master is a business decision, made separately.”

Open **Master → Items** briefly to show it is unchanged.

## 11 · Reports — 2m

**Output → Reports.** Show the nine cards.

Open the **Variance Report**. Apply a shop filter.

> “Every report supports search, filters, sorting and export.”

Click **Excel**, open it.

> “Filtered rows, formatted, with the filters printed at the top so the file
> explains itself.”

Back, then **PDF**.

> “And the same thing for sending or filing — who generated it, when, and the
> filters applied.”

## 12 · Final Output and OneDrive — 1m 30s

**Output → Final Output → Generate Final Output.** Choose the verified audit,
**Generate**.

Point at the status: **Not Uploaded**.

> “Generated, and nothing has left the application. That was explicit: the upload
> only ever happens when someone chooses it.”

Click **Share**, read the dialog, confirm.

> “That's the only moment the file leaves.”

Show **Uploaded** with the timestamp.

> “Uploaded, with the item recorded and the attempt count kept. If it fails, you
> get the reason and a Retry — not a silent failure.”

## 13 · Access control — 1m

Sign out. Sign in as **Shop User** (`annanagar@pharmaverify.com`).

> “Same application, different person.”

Point at the sidebar: no Stock Import, no Users, no Activity Log.
Open **Item Stock**: one shop only.

> “And this isn't the menu hiding things. The server refuses the request whatever
> the browser sends — that's where authorisation actually lives.”

## Closing — 30s

> “That's the full path: master data, stock loaded from the file, the count
> arriving from the device, verification, variance, the adjustment applied
> immediately, stock take for what isn't in the file, reports, and the final
> output shared to OneDrive — with a record of every change throughout.”

---

## If asked

**“Is this on SQL Server?”**
> “SQL Server is the target and the schema is built for it — there's a generated
> SQL Server script in the repository. Development ran on MySQL because the SQL
> Server driver wasn't on the build machine, and the data layer deliberately uses
> no engine-specific SQL. Point it at your instance and run the migrations. That
> dependency is written up in the assumptions document.”

**“Is the OneDrive upload real?”**
> “The Microsoft Graph integration is fully written — client credentials, chunked
> upload for large files, error handling. It's running against a demonstration
> driver today because we don't have your Azure application registration yet.
> Supplying those details is one environment change; no code.”

**“Where's the Android app?”**
> “Out of scope for these two days. What's built is the endpoint it will post to,
> with the contract documented and duplicate handling proven. The simulator uses
> the same endpoint — when the device arrives, nothing on the server changes.”

**“Can we approve adjustments before they post?”**
> “Not as specified — the requirement was explicit that adjustments post
> immediately with no approval workflow, so that's what it does. If you want an
> approval step later, the adjustment history table already holds everything a
> queue would need.”

**“What if two people verify the same line?”**
> “Last save wins, and both attempts are in the activity log with their values,
> so you can see what happened.”

**“How big a stock file can it take?”**
> “Twenty megabytes, imported in batches. The largest we've exercised is a few
> thousand rows, which imports in seconds.”

---

## Recovery

| If | Do |
| --- | --- |
| The import file is rejected | Say so plainly and use the pre-seeded stock — the point about replacement still stands |
| The audit number is taken | Increment it; that refusal is itself a feature worth showing |
| The upload fails | Show the failure and the Retry — it demonstrates the failure path |
| A screen is slow | Keep talking through what it is doing; do not click repeatedly |
| Something is genuinely broken | Move to the next step. Do not debug in front of the client |
