# 13 — User Guide

For the people who run stock verification. No technical knowledge assumed.

---

## What this application is for

Your shops count their stock on handheld devices. This application holds what the
system *thinks* each shop has, receives what was actually *found* on the shelf,
shows you where the two disagree, and lets you put the system right.

The path is always the same:

```
Load the stock  →  Receive the count  →  Check it  →  See the difference
   →  Fix the stock  →  Produce the report  →  Share it
```

---

## Signing in

Go to the application address, enter your email and password, and click **Sign
in**.

If you see *“The email address or password is incorrect”*, check both, mind the
capitals. If you see *“This account has been deactivated”*, ask your
administrator to reactivate it.

What you can see depends on your role. A Shop User sees only their own shops. If
a menu entry is missing, your role does not include it — that is deliberate, not
a fault.

---

## The Dashboard

The first screen after signing in.

| Card | Meaning |
| --- | --- |
| Active Shops / Items / Stock Records | The size of what you are working with |
| Submissions Today | Counts that arrived from devices today |
| **Pending Verification** | Counted lines nobody has checked yet |
| **Variance Items** | Lines where the count and the system disagree |
| **Pending Adjustments** | Differences not yet applied to the stock |
| Completed Audits | Counts that are finished |

The three in bold are your work list. Click any card to go straight to it.

Below the cards: the variance breakdown, recent submissions, recent audits,
recent adjustments, and Quick Actions for the jobs you do most.

---

## Master — Shops, Items and Devices

Set these up once, then change them rarely.

### Shops

**Master → Shops.** Each branch whose stock you verify.

To add one: **Add Shop**, fill in the code (e.g. `PHM004`), the name and the
address, then **Create shop**. The shop code must be unique.

To stop using a shop without losing its history, use the toggle in the row to
deactivate it.

### Items

**Master → Items.** Your product list. Each has a product code, a barcode, a
description, a unit of measure and a price.

Search matches the code, the barcode and the description, so any of them will
find a product.

### HHT Devices

**Master → HHT Devices.** The handhelds. Each belongs to one shop.

A device code such as `HHT-01` must be unique *within its shop*. Two shops may
both have an `HHT-01` — they are different devices.

> **Worth knowing.** A count is identified by **shop, device and audit number
> together**. Several devices in one shop can be on the same audit number at the
> same time, and devices drift out of step. That is normal, and the application
> expects it — which is why an audit number is never shown on its own.

---

## Stock Import — loading what the system holds

**Master → Item Stock Import.**

1. Choose the **Excel file** (`.xls` or `.xlsx`).
2. Choose a **shop** only if you want to import that one branch. The business
   Stock Report names its own branches, so you can normally leave this set to
   *All shops named in the file*. An older single-sheet file does need a shop.
3. Click **Import Stock**.
4. Read the confirmation carefully, then **Import and replace**.

A large report takes a minute or two. Leave the tab open while it works — the
screen tells you what stage it has reached.

### The one thing to remember

**Importing replaces stock.** Everything a shop held before is removed, and the
file takes its place. That is what the business wants — the file is the truth —
but it means importing the wrong file matters, which is why you are asked to
confirm.

A Stock Report replaces the stock of every branch it covers. Branches the file
does not mention are left completely alone.

### The summary

| Figure | Meaning |
| --- | --- |
| Total rows | Data rows in the file |
| Imported | Rows now in the system |
| Failed | Rows rejected, with a reason |
| Previous records replaced | What was removed |

If any rows failed, click the red number to see each one: the row, the column,
the value and what was wrong with it. Fix those rows and import again.

### If the file cannot be read at all

You will see a message such as *“The file is missing the required column(s):
System Stock.”* Nothing is changed — the shop still has exactly what it had.

### Which file to use

**The business Stock Report** is the normal file. It has three sheets — `stock`,
`all batches` and `Item Master` — and covers every branch it was run for. You do
not need to choose a shop for it.

**An older single-sheet file** still works for one shop. It needs the columns
**Product Code**, **Product Description** and **System Stock**; Shop, Barcode,
UOM, Price, Batch, Expiry Date and Shelf Location are optional. Headings are
matched sensibly — “Item Code”, “Product Code” and “SKU” all work, as do
“Closing Stock”, “Qty” and “System Stock”. For this file you must choose the
shop.

---

## Item Stock — what each shop holds now

**Stock Verification → Item Stock.**

Filter by shop, search for a product, or narrow by expiry. Stock expiring within
90 days is shown in amber, and the **Expiring soon** chip filters to it.

The same product appears once per shop and per batch. That is correct: three
shops holding the same medicine is three separate records.

---

## HHT Submissions — counts arriving from the devices

**Stock Verification → HHT Submissions.**

Each row is one completed count. Devices hold everything locally while counting
and send it once, at the end — so a submission appearing here means that count
is finished.

| Status | Meaning |
| --- | --- |
| Accepted | The count was received and an audit created |
| Duplicate Ignored | The same count arrived again, usually a retry after poor signal. Nothing was duplicated |
| Rejected | The submission could not be accepted; the reason is shown |

Click **Audit** on a row to open what was counted.

### The HHT Simulator

**HHT Submissions → HHT Simulator.** Sends a count exactly as a real device
does, for demonstrations and testing. Choose a shop, a device and an audit
number, load the shelf, change some quantities, then **Share / Submit**.

Nothing is sent until you press that button — just like the real device.

---

## Stock Audit — reviewing a count

**Stock Verification → Stock Audit**, then click a row.

The header tells you which count this is: audit number, shop, device, who counted
and when.

The summary strip tells you how it went:

| Figure | Meaning |
| --- | --- |
| Lines counted | Products in the count |
| Short | Fewer on the shelf than the system says |
| Excess | More on the shelf than the system says |
| Matched | Agreement — nothing to do |
| Pending verification | Lines nobody has checked yet |
| Net variance | The overall difference |

Below, every counted line with **System**, **Physical** and **Variance** side by
side. Red is short, green is excess, grey is a match.

If a banner says some counted items are not in the shop's stock file, those are
products found on the shelf that the stock data does not contain. They cannot be
adjusted — record them as a Stock Take instead.

---

## Verification — checking the count

**Stock Verification → Verification** shows everything still to check, across all
audits. It opens filtered to lines still pending.

To check a line, click the pencil. You will see:

- the **system quantity**,
- the **physical quantity**, which you can correct,
- the **variance**, which updates as you type.

You may also correct the batch, expiry, shelf location and add a remark. Then
**Save and verify**.

To accept a whole count at once, open the audit and use **Verify all lines**.

> Every change is recorded — who made it, when, which field, the old value and
> the new value. You can see this in Administration → Activity Log. This is a
> feature: corrections are expected, and they are traceable.

---

## Variance — where things disagree

**Stock Verification → Variance.**

```
Variance  =  Physical Quantity  −  System Quantity
```

System says 100, shelf has 95 → variance is **−5**. Five short.

The summary strip shows lines in scope, short lines and units, excess lines and
units, matched lines, the net variance, and how many still await adjustment.

Filter by direction (short, excess, matched, all), shop, device, audit number or
date. Export what you are looking at to Excel or PDF with the buttons at the top.

---

## Stock Adjustment — putting the system right

From Variance, click **Adjust** on a line — or tick several and use **Adjust N
selected**.

The dialog shows what will change: the current quantity, the counted quantity and
the difference. Add a reason if it helps, then **Post adjustment**.

> **The adjustment happens immediately.** There is no approval step and nothing
> waits for a supervisor. The moment you post it, the system stock becomes the
> quantity that was counted.

**Stock Verification → Stock Adjustment** is the history: what was changed, from
what to what, by whom, when and why. Export it to Excel from the same screen.

A line already adjusted cannot be adjusted again — the option is simply not
offered.

---

## Stock Take — stock that isn't in the file

**Stock Verification → Stock Take.**

Sometimes a shelf holds a product the stock file knows nothing about. That is not
a variance — there is nothing to compare it with. It is a stock take.

At the top, a panel lists products counted but missing from the stock file, each
with a **Record** button that fills the form for you.

To record one manually: **Record Stock Take**, choose the shop, describe the
product, enter the quantity, add the batch, expiry and shelf if you know them,
then save.

> Recording a stock take does **not** create a new product in the item master.
> Whether the product should be added is a business decision, made separately.

---

## Reports

**Output → Reports.** Nine reports:

| Report | Answers |
| --- | --- |
| Variance Report | Where exactly did the count and the system disagree? |
| Audit No. Based Report | What happened in this particular count? |
| Individual Shop Based Report | What does this shop hold? |
| Overall Stock Report | What do all the shops hold together? |
| User Log Report | Who did what, and when? |
| Stock Adjustment Report | What was changed, from what to what? |
| Detailed Report | Everything about every counted line |
| Variance Summary Report | How did each count do, in one line? |
| Stock Occurrence Report | Where does this product turn up, and how often? |

Open a report, set the filters, sort by clicking a column heading, then export:

- **Excel** — for further analysis. Filtered, formatted, with the filters printed
  at the top so the file explains itself.
- **PDF** — for sending or filing. Includes who generated it and when.

Exports always match what you are looking at, filters included.

---

## Final Output and OneDrive

**Output → Final Output.**

### Generating

Click **Generate Final Output**, choose the audit, click **Generate**. A file is
produced containing the audit's details and every counted line.

Its OneDrive status will read **Not Uploaded**. Nothing has left the application.

### Sharing

When you are ready, click **Share** on the row and confirm.

> This is the **only** moment the file leaves the application. Nothing uploads
> automatically, on a schedule, or when the file is generated.

You will see progress, then **Uploaded** with the time.

### If it fails

The status becomes **Failed** with a plain explanation — no space in OneDrive, no
permission, folder not found, and so on. A **Retry** button appears. Click it
when the cause has been addressed. The attempt count tells you how many tries a
file has taken.

**Download** gives you the file directly, whether or not it has been shared.

---

## Administration

### Users

**Administration → Users.** Add people, set their role, choose which shops they
may see, reset passwords, activate and deactivate.

| Role | Can do |
| --- | --- |
| Administrator | Everything, including users and settings |
| Supervisor | Everything operational: import, verify, adjust, report, share |
| Shop User | View their own shops, record stock takes, run reports |

Leaving the shop selection empty means the user sees every shop. The permissions
a role carries are listed as you choose it.

The last active administrator cannot be deactivated or demoted — otherwise nobody
could administer the system.

### Activity Log

**Administration → Activity Log.** Everything significant that has happened: who
signed in, which stock file replaced which shop's stock, which counts were
corrected and from what to what, which adjustments were posted, which files went
to OneDrive.

### Settings

**Administration → Settings.** Company name for reports, report footer, date
format, default unit of measure, expiry warning period, variance tolerance and
the OneDrive folder.

The Integrations panel shows whether OneDrive is running against Microsoft 365 or
in demonstration mode. Credentials are never shown here — they live on the server
and cannot be read through the application.

---

## Quick answers

**Why can't I see a menu entry?** Your role does not include it. Ask your
administrator.

**I imported the wrong file.** Import the correct one for that shop. It replaces
what is there. Adjustments already posted are unaffected.

**The same audit number appears twice.** That is normal — different devices, same
number. Look at the device beside it.

**A count arrived twice.** The second is marked *Duplicate Ignored*. Nothing was
counted twice.

**Can I undo an adjustment?** Not directly. Post a further adjustment to correct
it; both appear in the history.

**A product isn't in the stock file.** Record it as a Stock Take.

**My export is empty.** Your filters match nothing. Clear them and try again.

**The upload failed.** Read the reason, deal with it, then click Retry.
