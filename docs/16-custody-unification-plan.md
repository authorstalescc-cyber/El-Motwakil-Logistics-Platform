# Custody System Unification Plan — Effective 2026-07-29

> **Status:** DRAFT — awaiting owner approval to start Phase 1.
> **Cutoff line:** end of **2026-07-28** (already closed/locked). New system starts **2026-07-29**.
> **Language note:** This document is in English for external review (Codex). The system UI and
> all user-facing strings are Arabic (Egyptian colloquial). Domain terms are glossed in §1.
> **Every number in this document was measured directly against the production database on
> 2026-07-29/30. Nothing is estimated or assumed.**

---

## Progress Tracker

Mark items `[x]` as they complete. Do not mark a phase complete until its verifier passes.

| Phase | Title | Status |
|---|---|---|
| 0 | Freeze the past | ✅ **Done** |
| 1 | Cutoff epoch + missing deposits | ✅ **Done** — activated on production 2026-07-30 |
| 2 | Single-entry cash recording | ✅ **Done** — deployed **and activated** on production 2026-07-30 |
| 3 | Driver custody charged to the paying wallet | ✅ **Done** — 3.1 · 3.2 · 3.3 · 3.4 · 3.5 |
| 4 | Carry-forward + closing screen | 🟡 **4.3 done & deployed.** 4.1 redefined by the owner as a *manual* carry-over button — designed, not yet built |
| 5 | Remove duplicate paths | ✅ **Done — no code needed.** 3 of 4 items rested on wrong premises; nothing dropped |

---

## 1. Glossary (for the reviewer)

| Arabic | Meaning in this system |
|---|---|
| **عهدة** (*custody*) | A cash float advanced to someone who will spend it on the company's behalf. |
| **بوليصة** (*bawala*) | Waybill / bill of lading. One truck trip. Table `bawalis`. |
| **عهدة السائق** (*driver custody*) | Cash handed to the truck driver for road expenses. Column `bawalis.custody`. |
| **مندوب** (*rep*) | Field representative who pays receipts/expenses at ports and government offices. |
| **إذن** (*permit*) | Customs clearance permit/certificate. Table `permits`; its line items are `permit_stages`. |
| **خزنة المكتب** (*office safe*) | The office cash drawer. Balance computed by `App\Models\Closing`. |
| **التقفيل اليومي** (*daily closing*) | Day-end close that freezes a day's figures and carries the balance forward. Table `daily_closings`. |
| **إيصال / مصروف / نثرية** | receipt / expense / petty-cash item — the three kinds a rep can submit. |

**Money-direction warning (project-wide invariant):** the company **owes** its clients. "Payments"
are money **leaving** the company. Do not flip signs. This plan does not touch client accounting.

---

## 2. Problem Statement

The system was built on a wrong assumption: that cash flows **bank → office drawer → distributed**.

The actual operation (confirmed by the owner, 2026-07-30):

- The manager (Ayman/Ahmed) withdraws cash from the bank and hands it **directly to a rep**. It
  usually never enters the office drawer.
- A rep may redistribute to other reps.
- Reps spend that float on: permit receipts, expenses, **and driver custody**.
- Driver custody has a paper/cash split: a waybill recording `7100` means the driver actually
  received `7000`. The `100` is a notional amount left for the waybill's owner ("يسترزق بيها لو
  باعها"). **Nobody actually pays it.** Exception: company-owned trucks and special trips are paid
  in full with no `100` deduction.

### 2.1 Three structural leaks (measured)

| # | Leak | Measured impact |
|---|---|---|
| 1 | A withdrawal is recorded **twice**: `manual_custody` (safe top-up) **and** `rep_custody_deposits` (rep deposit) | 2026-07-26 and 2026-07-27: the two totals match **to the pound** (124,000 and 132,560) |
| 2 | Driver custody is deducted from the **office safe** while the **rep** actually paid it | Rep balances inflated by **~690,500** |
| 3 | Reps have no "I handed the driver his custody" action on an existing waybill | 81,000 across 6 stuck submissions miscategorised as receipt/expense |

### 2.2 Proof of leak #2 (pure arithmetic)

```
Reported "outstanding with reps"          704,605
− Office safe balance at 2026-07-27        17,575
= Gap                                     687,030

Driver custody issued since 2026-07-20    690,500   ← 3.5k apart over a full month
```

**Mechanism:** the double entry cancels out *arithmetically* in the safe (money enters on paper,
then leaves when the rep's spending is approved), but the **rep wallet is never debited for driver
custody**, so it accumulates indefinitely.

> ⚠️ **Correction to an earlier diagnosis:** the office safe is **not** the inflated figure — it is
> roughly correct. The **rep balances** are the inflated ones. An earlier draft of this analysis had
> this backwards.

### 2.3 Immediate consequence

The 6 submissions still pending with `entry_date < 2026-07-29` (total **81,000**) are all driver
custody **already deducted from the safe via their waybills**:

| Rep | Kind recorded | Amount | Note |
|---|---|---:|---|
| عبدالرحمن | expense | 40,000 | «عهده 5 عربيات» |
| عبدالرحمن | expense | 9,000 | «عهده عربيه» |
| احمد مرزوق | receipt ×4 | 32,000 | «عهد عربيه» |

**Approving them would double-charge.** The owner was right to hold them. Phase 1.4 makes the
system refuse them rather than relying on memory.

---

## 3. Current Data Model (as-is)

Tables that hold or move custody today:

| Table / column | Represents | Rows (prod) | Verdict |
|---|---|---:|---|
| `manual_custody` | Cash into the office safe | 66 | Keep — but stop mirroring |
| `bawalis.custody` | Driver custody on a waybill | 187 rows > 0 | Keep — fix which wallet is charged |
| `bawalis.custody_rep_id` (v60) | Same, paid from a rep's wallet | **0** | Keep — unused because UX is wrong |
| `rep_custody_deposits` | Cash handed to a rep | 31 | Keep — add `source` |
| `rep_custody_settlements` | Rep returns leftover cash | 5 | Keep |
| `rep_custody_transfers` (v46) | Rep → rep transfer | 5 | Keep — under-used |
| `rep_driver_handovers` (v45) | Rep hands a driver custody, **not linked to any waybill** | 8 / 40,000 | Merge into Phase 3; screen already removed in v60 but the table **still debits** |
| `rep_misc_expenses` (v39) | Rep petty cash, no permit | 52 | Keep |
| `permit_stages.paid_by_rep_id` (v35) | Permit line item paid by a rep | 175 / 192,209 | Keep |
| `rep_entries` kind=`advance` (v21) | A *second* way to advance cash to a rep | 1 | Consolidate (Phase 5) |
| `driver_custody`, `rep_settlements` | — | 0, 0 | Dead — drop (Phase 5) |

**So: driver custody has 3 possible paths and rep float has 2 sources.** That is the "scattered"
problem the owner described.

### 3.1 The v42 rule that must be reversed

Today (`App\Models\Closing::sumExpenses`, documented in `RepCustody`'s header):

- Depositing to a rep → **no effect on the safe** (treated as an internal transfer).
- Rep's approved spending → **debited from the safe** on the spend date.
- Returning leftover → no effect.

This was correct *only* while every deposit had a mirroring `manual_custody` top-up. Once deposits
come straight from the bank (the real situation), the safe is debited for money that never entered
it, and drifts negative. **Phase 2 reverses this.**

---

## 4. Target Model

One concept: **wallets**. Cash lives in exactly one wallet at any moment.

```
BANK ──► wallet (office drawer | rep | visa card)
             │
             ├─► transfer to another wallet   (ONE record: −here, +there)
             └─► spend  (receipt | expense | driver custody | petty cash)
```

Three invariants:

1. **A withdrawal is recorded exactly once**, tagged with the wallet it landed in.
2. **A spend debits the wallet that actually paid it** — never a different one.
3. **Driver custody debit = `custody − 100`** from whichever wallet paid, with the existing
   exempt-cars rule (`custody_deduction_exempt_cars` setting) charging the full amount.

Resulting closing statement:

```
carried forward + received today = available
− receipts − expenses − driver custody
= remaining, broken down per wallet (drawer, rep A, rep B, …)
```

---

## 5. Opening Balances at 2026-07-29 (owner-confirmed)

Measured from production and matched line-by-line against the owner's own count:

| Rep | Deposits | Transfer in | Transfer out | Balance | Pending | **In hand** |
|---|---:|---:|---:|---:|---:|---:|
| ابراهيم خميس زكي | 50,000 | 10,000 | 30,000 | 30,000 | 17,785 | **12,215** |
| احمد مرزوق | 0 | 30,000 | 10,000 | 20,000 | 2,525 | **17,475** |
| السيد الفنان | 21,000 ⭐ | 0 | 0 | 21,000 | 0 | **21,000** |
| عبدالرحمن | 10,000 ⭐ | 0 | 0 | 10,000 | 9,080 | **920** |
| بدر محمد | 0 | 0 | 0 | 0 | 1,647 | **−1,647** |
| محمد جمال · محمد فكري | 0 | 0 | 0 | 0 | 0 | **0** |

⭐ = the **only two records to be inserted**. Everything else is already in the database and correct.

Owner's own words → computed result:

| Owner said | Computes to | Match |
|---|---|---|
| «إبراهيم استلم 50000، أعطى 20000 لمرزوق، وسلم إيصالاته المسجلة» | 50,000 − 20,000 − 17,785 = 12,215 | ✅ |
| «مرزوق افتتاحي هيكون الـ20000 اللي استلمها من ابراهيم» | 30,000 − 10,000 − 2,525 = 17,475 | ✅ |
| «السيد نسيت أضيفله عهدة، المفروض 21000» | insert 21,000 → 21,000 | ✅ |
| «عبدالرحمن المفروض معاه 10000 اللي صرف منها 9080» | insert 10,000 − 9,080 = 920 | ✅ |
| «بدر ملهوش عهدة، سيب بنوده زي ما هي» | 0 − 1,647 = −1,647 | ✅ |
| «الباقي ملهوش عهدة لسه» | 0 | ✅ |

**Negative balance for بدر is accepted** (owner's explicit instruction). It is consistent with the
existing v49 rule that a negative rep balance means *the company owes the rep*, and it must not be
clamped to zero.

### 5.2 Wallet split of the 2026-07-28 closing balance (owner-supplied)

The owner decomposed the closing figure by hand — and it reconciles **exactly**:

| Wallet | Balance at end of 2026-07-28 |
|---|---:|
| Office drawer (Ayman/Ahmed) | 56,780 |
| عبدالرحمن (still in his pocket) | 2,930 |
| السيد الفنان (carried over for the 29th) | 8,000 |
| **Total** | **67,710** |

`56,780 + 2,930 + 8,000 = 67,710` — identical to the system's stored closing balance for
2026-07-28.

> **This is the strongest single confirmation of the diagnosis in §2.** The owner's own hand
> breakdown proves that "office safe balance" is not drawer cash — it is
> `drawer + Σ(cash sitting in reps' pockets)`. The system reports one pooled number for what are
> physically three separate wallets. Phase 2 is what splits them.

**Implication for the opening balances:** two reps carry a non-zero balance into 2026-07-29, so
their §5 targets are **totals**, not fresh deposits. See §9 Q1 — this changes the size of the two
records to be inserted in step 1.3 and must be resolved before Phase 1 executes.

### 5.1 ⛔ Hard constraint from the owner

> **"البنود أهم شيء يجب ألا نمسها إطلاقًا" — the submissions are the most important thing; do not
> touch them at all."**

**No row in `rep_submissions` or `permit_stages` is deleted or modified anywhere in this plan.**
Old submissions stay exactly as they are, in their current status. Protection comes from
**blocking approval**, not from deleting.

---

## 6. Cutoff Mechanism — `rep_custody_epoch`

### Decision: a date filter, not corrective rows

One setting `rep_custody_epoch = 2026-07-29`. All rep-wallet balance math counts only movements on
or after it.

**Rejected alternatives and why:**

| Alternative | Why rejected |
|---|---|
| Insert a settlement per rep on 2026-07-28 zeroing the old balance | Records "we received 51,665 cash back from him", which is **false**, and permanently corrupts the settlements ledger and the "total returned" statistic |
| Tag pre-cutoff waybills as rep-paid so rep balances drop naturally | There is **no data** saying which rep paid which waybill. That would be guessing. |
| Delete or edit old rows | Violates §5.1, and destroys audit history |

**Advantages of the filter:** no row is deleted or edited · the old history stays viewable in an
"archive before 2026-07-29" section · **fully reversible** by clearing the setting.

### 6.1 ⚠️ Synchronisation warning (same class as CLAUDE.md warning #6)

The single source of the filter is **`RepCustody::epochSql(string $dateColumn): string`**.
**Never hand-write the date condition.** It must be threaded through **every** balance point, or a
card will report one number and a statement another, with no visible error:

```
RepCustody         depositsTotal · spentTotal · returnedTotal · transferNet · balance · breakdown
                   dayLedger · dayTotalsByRep · daySpending · dayDeposits
                   cards · totalOutstanding · deposits · spending · dayActivity
                   openDays · clearSnapshot · settleZero · dashboard
RepTransfer        inTotal · outTotal · inTotalsByRep · outTotalsByRep
RepMiscExpense     totalFor · totalsByRep
RepDriverHandover  totalFor · totalsByRep
RepBillCustody     totalFor · totalsByDay · totalsByRep · totalAll · forRep
RepPortal          dayPocket · pendingAmount · mySubmissions · myCounts · recentDeposits
RepWatch           team · rep
```

`epochSql()` returns `''` when the setting is absent ⇒ **byte-for-byte the old behaviour**, so the
system keeps working before activation and during rollback. This mirrors the established pattern of
`RepBillCustody::officeExcludeSql()`, `RepMiscExpense::cashOnlySql()` and `Permit::cashOutSql()`.

### 6.2 Scope boundary

The epoch applies to **rep wallets only**. It must **not** touch:

- `App\Models\Closing` / `Custody` (office safe) — the safe is anchored by `daily_closings`, and
  2026-07-28 is already closed, which freezes everything before the cutoff.
- Any client-facing accounting (invoices, statements, debts).

---

## 7. Phased Plan

### Phase 0 — Freeze the past

- [x] **0.1** Confirm 2026-07-28 is closed (`daily_closings`) — *verified: `lastClosedDate = 2026-07-28`, stored `closing_balance = 67,710.00`*
- [x] **0.2** Owner closed 2026-07-28 manually
- [x] **0.3** Confirm and lock the opening-balance table in §5 against production — *verified, exact match*
- [x] **0.4** Full production DB backup, integrity-checked for the `Dump completed` trailer —
      *`database/backups/pre-custody-unify-20260730-132439.sql.gz` (477K), verified on the server
      **and** on the local copy*

---

### Phase 1 — Cutoff epoch + the two missing deposits

**Goal:** rep balances equal §5 to the pound, with zero effect on the office safe.

- [x] **1.1** Add setting `rep_custody_epoch` and `RepCustody::epoch()` / `epochSql()` / `refreshEpoch()`
      (returns `''` when unset ⇒ old behaviour byte-for-byte). *Smoke-tested: with the setting absent
      all balances are identical to before; with it set the bound applies.*
- [x] **1.2** Thread `epochSql()` through every call site listed in §6.1 — **47 sites in 7 models**
      - [x] `RepCustody` — 17 sites (`dayClause` is the choke point for `depositsTotal` /
            `spentTotal` / `returnedTotal` / `deposits`; plus `dayLedger` ×4, `dayTotalsByRep` ×4,
            `dayDeposits`, `cards` ×3, `totalOutstanding` ×3, `spending`, `settlements`,
            `openDays`, `dashboard` ×7)
      - [x] `RepTransfer` — 6 sites
      - [x] `RepMiscExpense` — 5 sites
      - [x] `RepDriverHandover` — 6 sites
      - [x] `RepBillCustody` — 5 sites
      - [x] Smoke test: **35 public functions invoked, zero SQL errors**, `php -l` clean on all 5 models
      - [x] `RepPortal` — 4 sites (`mySubmissions` · `myCounts` · `pendingAmount` · `pingState`;
            `dayPocket` inherits via `balance`/`pendingAmount`)
      - [x] `RepWatch` — 4 sites (`submissions` · `pendingCounts` · `entryCountsFor` · `lastActivity`;
            `team`/`rep` inherit because they delegate to `RepCustody`)
- [ ] **1.3** Insert the two missing deposits dated `2026-07-29`, note «عهدة ٢٩/٧»:
      - [ ] السيد الفنان — 21,000
      - [ ] عبدالرحمن — 10,000
- [x] **1.4** Reject approval of any submission with `entry_date < rep_custody_epoch`, with an
      explicit Arabic message explaining that its custody is already charged to its waybill.
      Guard is `RepInbox::epochBlock()`, called from **`RepInbox::approve` (the model)**, not the UI.
      `approveMisc` is private and reached only through `approve`, and `approveMany` loops over
      `approve` — so one guard covers all three paths.
      *Behaviourally tested inside a rolled-back transaction: a 2026-07-28 item is **refused** with
      the Arabic message, a 2026-07-29 item is **approved**, and with the setting cleared the old
      item is approved again (proving the feature is off by default).*
- [x] **1.5** Add an "archive before 2026-07-29" collapsible section to the rep card so the old
      history stays readable without entering the balance.
      `RepCustody::archiveBefore()` (read-only) + a `<details>` block in
      `app/Views/rep_custody/_tab_account.php`.

      ⚠️ **Why this is not optional:** the moment the cutoff is switched on, every pre-cutoff
      movement vanishes from the rep card. Without this block the owner opens a card and
      concludes **the data was deleted**. The section states the excluded figure and explains it.

      ⚠️ `archiveBefore()` is the **one sanctioned place that hand-writes the date condition**,
      because it needs the exact inverse of `epochSql` (`< epoch`). It is display-only and feeds
      no balance, so there is no sync hazard.

      ⚠️ **`stat` refuses to render without `drill` or `url`** ("ممنوع رقم أصمّ") — the first
      version would have silently rendered as HTML comments. The three cards now open a
      pre-rendered inline `#preEpochDrill` block, so no extra query.

      *Rendered against production data: إبراهيم shows 93,560 in / 34,895 spent / **61,665
      excluded**, 3 cards wired to the drill, zero PHP notices; with the setting cleared the
      section disappears entirely.*
- [x] **1.6** Write `bin/verify_custody_epoch.php` and register it in `bin/run_verifiers.php`
      — **37/0 against a local restore of the production backup, 32/0 against the dev DB.**

      ⚠️ **The verifier asserts structure, not memorised numbers.** Production data moves while
      we work (proved below), so §5's checks recompute the expected balance with **raw SQL
      independent of the models** and compare. Plus a meta-check — *"the filter must have
      excluded something for at least one rep"* — without which every other check would pass
      vacuously on an inert filter. It reports **7 of 9 reps had pre-cutoff balances excluded**.

      ⚠️ **The rejection-reason trap (v49) hit again and is now guarded.** On production
      2026-07-28 is closed, so `Closing::guard` refuses the approval **too**. Asserting only
      "it was refused" passes without ever exercising the new guard. The check now requires the
      message to contain «بداية النظام الجديد».

      ⚠️ **The `«$var»` PHP trap hit for the 7th time** while writing this verifier. Use `{$var}`.

#### Two data findings from running against real data

1. **بدر: 2 of his 4 pending items on the 29th are `is_visa=1`** (342 + 500). Visa items never
   leave his cash float, so his true position is **−805, not −1,647**. The −1,647 figure in §5
   summed all four. The system is right; the plan's number was wrong.
2. **احمد مرزوق: a new 17,400 transfer was recorded on the 29th** *after* the §5 table was
   captured, so his balance is now **0, not 17,475**.

**Consequence: the §5 table is a snapshot, not a target.** Do not hard-code it anywhere. The
owner's decisions that remain binding are the two deposits (السيد 21,000 · عبدالرحمن 10,000) and
the cutoff date — everything else must be recomputed at execution time.
- [x] **1.7** Deployed 2026-07-30 — **11 files, all checksum-matched, verifier 37/0 on production,
      `/login` and `/m/login` both 200.** With the setting absent the balances are byte-identical
      to before (safe 67,710.00 · outstanding 704,605.00).

      ⚠️ **Deployed with a scoped upload, NOT `bin/deploy.sh`.** The working tree holds **127
      modified files** from concurrent sessions and `deploy.sh` rsyncs everything, which would
      publish other people's unfinished work. Before uploading, each of my files was compared
      against production: three (`RepPortal`, `RepInbox`, `_tab_account`) already differed from
      git HEAD — i.e. carried deployed-but-uncommitted work — and each was confirmed to be a
      **subset** of my local copy, so nothing was reverted. Same ordering as `deploy.sh`:
      backup → upload → permissions → checksum → verifier → HTTP 200.

- [x] **1.8 — ACTIVATED on production 2026-07-30** via `bin/activate_custody_epoch.php`
      (idempotent; `--dry-run` previews inside a rolled-back transaction; `--off` reverts).
      - [x] Inserted the two missing deposits (السيد 21,000 · عبدالرحمن 10,000) dated 2026-07-29
      - [x] Set `rep_custody_epoch = 2026-07-29`
      - [x] Verifier **37/0 on production with the feature live**; `/login` and `/m/login` 200

#### Result

| Rep | In hand (new) | Pre-cutoff archive (excluded) |
|---|---:|---:|
| ابراهيم خميس زكي | **12,215** | 61,665 |
| السيد الفنان | **21,000** | 384,190 |
| محمد جمال | **17,400** | 9,440 |
| عبدالرحمن | **920** | 73,245 |
| احمد مرزوق | **0** | 163,170 |
| محمد فكري | **0** | 2,245 |
| بدر محمد | **−805** | 650 |
| **Outstanding with reps** | **81,000** *(was 704,605)* | |
| **Office safe** | **67,710** — *unchanged* | |

**Cross-check that closes the loop:** total rep balance after activation is **81,000**, which is
*exactly* the bank cash the owner listed for 2026-07-29 (50,000 + 21,000 + 10,000). The 623,605
that disappeared was the phantom driver custody diagnosed in §2.2 — money the reps had already
handed to drivers, still sitting on their books.

⚠️ Two verifier bugs were caught and fixed during activation, both false-negative classes:

1. **Dry-run previewed nothing.** The setting write and the deposit inserts were both skipped
   under `--dry-run`, so the "after" table rendered as a copy of "before". Both now run inside
   the transaction that rolls back, so the preview is truthful.
2. **§8 compared incompatible baselines.** §1 force-disables the feature to test it; the "before"
   snapshot inherited that state and was then compared against an "after" with the feature live.
   It failed the moment the feature was switched on. The snapshot now uses the real setting.

**Acceptance criteria (all must hold):**

- [ ] Each rep's balance and in-hand figure equals §5 **to the pound**
- [ ] `Closing::currentBalance()` is **unchanged** before vs after
- [ ] Every row in `daily_closings` is byte-identical before vs after
- [ ] `SELECT COUNT(*), SUM(amount) FROM rep_submissions` unchanged, and no row's `status` changed
- [ ] Attempting to approve a pre-epoch submission fails with the Arabic message (tested by an
      actual call to the model, not by reading the UI)
- [ ] Clearing `rep_custody_epoch` restores the pre-change numbers exactly (rollback test)

---

### Phase 2 — Single-entry cash recording

**Goal:** eliminate the double entry permanently.

Add a **source** to the rep deposit: "where did this money come from?"

| Source | Effect on office safe | Effect on rep wallet |
|---|---|---|
| `bank` — straight from the bank *(the common case)* | none | + amount |
| `office` — out of the office drawer | − amount | + amount |
| `rep` — from a colleague | none | transfer (already exists) |

- [x] **2.1** `migrate_v61.php` adds `rep_custody_deposits.source`
      — **`enum('bank','office','carry')` default `'bank'`**, + index `(source, dep_date)`.

      ⚠️ **A third value was needed: `carry`.** `bank` and `carry` have identical mechanics (no
      safe effect) but different meaning — "withdrawn from the bank" vs "was already in his pocket
      before the cutoff" — and the distinction matters for drawer reconciliation and the audit
      trail. `carry` is used **once**, by the Q4 correction.

      The migration is **inert**: every existing row becomes `bank`, which is the no-safe-effect
      case = v42's deposit behaviour byte-for-byte. It asserts safe balance, every rep balance and
      every `daily_closings` row are unchanged, and refuses to record itself if any moved.

- [x] **2.2** "الفلوس جاية منين؟" added to the deposit form (`rep_custody/_tab_day.php`), defaulting
      to `bank`, and **shown only when the rule is active** (a field with no effect is just noise).
      `RepCustody::cleanSource()` is the guard — the form is not a source of truth.

      ⚠️ **A stale comment was corrected:** `depositStore` said *"no `Closing::guard` on purpose —
      a deposit never touches the safe"*. That is **false** for `source='office'`, which debits the
      safe on the deposit date; recording one on a **closed day** would change a frozen balance.
      The guard is now applied for `office` only.

- [x] **2.3** The v42 reversal — **from one shared source, `RepCustody::safeChargeSql()` /
      `officeDepositSql()`**, threaded through `Closing` (both the fast `movementTotals` path and
      the per-figure `sumExpenses`/`sumRepMisc` path, plus `hasMovementBetween` and
      `earliestMovementDate`), `DailyReview`, `DailyClosingController` (screen **and** print) and
      `bin/backfill_closings.php`.

      ⚠️⚠️ **The date bound is on the row's own date column, and that is what makes closed days
      safe.** `Closing::refreshLockedChainFrom()` recomputes closed days from raw rows and fires
      automatically under free-edit. Bounding on "today" or on a global flag would have let it
      **silently rewrite locked history**. With the bound on `s.entry_date` / `exp_date`, a
      pre-rule row is always in and a post-rule row is always out, no matter how often it is
      recomputed. The verifier asserts `refreshLockedChainFrom` changes **zero** closing rows.

      ⚠️ **`DailyReview` gained two sections, and their classification is what keeps the v50 parity
      rule true:** `rep_wallet` (paid from a rep's wallet — **informational**, does not block) and
      `rep_deposit` (cash out of the drawer to a rep — **cash, blocks**). Flipping either makes the
      review screen's cash total diverge from `Closing::dayView` silently.

      ⚠️ **Classification is on `paid_by_rep_id`, not on the joined rep name.** The name comes from
      a `LEFT JOIN` and is NULL if the rep row was deleted, which would classify a wallet-paid item
      as drawer cash — diverging from the SQL condition with no visible error.

- [x] **2.4** `manual_custody` now means *"cash that physically entered or left the drawer"* only.
      Nothing in the code creates mirror rows (verified — the mirroring was manual data entry).

- [x] **2.5** `bin/verify_safe_rule.php` — **48 checks**, registered in `bin/run_verifiers.php`.
      Asserts: rule off ⇒ byte-identical v42 behaviour · the activation key is **separate from
      `rep_custody_epoch`** · every safe-side file calls the shared source and none hand-writes the
      condition · both `Closing` paths agree · **money is conserved** (`safe + reps` unchanged when
      cash moves drawer→rep) · review-screen cash total == `Closing` expenses · closed days
      unchanged · and the closing screen actually renders the new sections with real amounts.

      **Honesty-tested in both directions:** removing the office-deposit branch from `sumExpenses`
      fails the review-parity check; removing it from `movementTotals` fails the money-conservation
      check with the exact evaporated amount.

- [x] **2.6** Deployed **and activated** 2026-07-30 — see §7.2.6 below.

#### 7.2.6 — Deployment and activation (2026-07-30)

Two separate, separately-reviewable steps, exactly as Phase 1:

| Step | Effect on any number |
|---|---|
| Upload 12 files | **none** — verified live: `newSafeRuleActive = 0` |
| `php bin/migrate_v61.php` | **none** — 33 existing deposits became `bank`; balances identical |
| `php bin/activate_safe_rule.php` | the intended change, previewed first with `--dry-run` |

```
before:  safe 66,985.00  +  reps 81,000.00  = 147,985.00
after:   safe 56,055.00  +  reps 91,930.00  = 147,985.00   ← conserved to the piastre
```

The safe now reads **56,055** — the owner's own drawer figure of 56,780 minus the 725 of real
business activity that happened while this work was in progress. Rep balances:
السيد 29,000 · ابراهيم 30,000 · محمد جمال 17,400 · عبدالرحمن 12,930 · احمد مرزوق 2,600.

⚠️ **Q4 was implemented via the durable mechanism, not the literal one** — see §9 Q4. One dated
`manual_custody` adjustment of −10,930 on 2026-07-29 plus two `carry` deposits, rather than
hand-writing 56,780 onto the closed 2026-07-28 row (which `refreshLockedChainFrom` would have
silently recomputed back to 67,710).

#### 7.2.8 — ⚠️ Owner review found two display faults and reversed one of my judgements (2026-07-30)

He read the closing screen and asked two questions. Both were fair, and the second one corrected me.

**① "Why don't the reps' 29/7 deposits show in the closing screen as عهدة مضافة?"**
Because they are `bank`-sourced — the money never entered the drawer, so it is correctly absent from
«عهدة مضافة للخزنة». Arithmetically right, but it left the day's picture incomplete. Added an
**informational** section `rep_bank` «عهدة نزلت للمناديب من البنك» — visible and readable, and it
**does not block the close** because it never touched the drawer. It renders regardless of whether
the new rule is active, so the day reads the same before and after activation. Office-sourced
deposits are skipped here — they already have their own **cash** section.

**② "Why did you add a negative row under عهدة مضافة? I told you to add the 10,930 to عبدالرحمن and
السيد."**
The reps *did* get it, and positively: rows `#253` +2,930 and `#254` +8,000 — their balances rose
920→12,930 and 21,000→29,000. What he was looking at was a **different row** (`#101`), the −10,930
drawer side, sitting inside the table whose section is titled «عهدة مضافة للخزنة». A negative there
reads as a deduction *from the reps*, which is wrong. Fixed twice over: the item title is now
**sign-aware** («كاش خرج من الدرج» when negative), and the row was **moved to 2026-07-28**.

**⚠️ And his instruction «افتح يوم 28 وحط التسوية» was right — my objection had been aimed at a
different thing.** I had refused option C because `refreshLockedChainFrom()` recomputes closed days
from raw rows and would silently revert a **hand-written** `closing_balance`. But putting a **real
dated row inside the day** and re-closing it is a different act entirely: recomputation finds the
row and yields 56,780 every time. **Durable.** `bin/move_carry_adjustment.php` does it inside one
transaction — reopen → re-date → re-close — and then *calls `refreshLockedChainFrom` itself* and
asserts the figure did not move, plus that the live balance and every rep balance are unchanged.

Result: 2026-07-28 now carries **56,780** — the figure the owner counted by hand — while the live
drawer stays 56,055 and the total stays 147,985.

**Lesson: "a correctly dated row" and "a hand-written number" are not the same risk.** I had
collapsed them into one objection.

⚠️ `Closing::closeDay()` returns only `ok` + `msg` — there is no `closing` key. Reading one printed
"closed at 0.00", which looks alarming and is meaningless.

⚠️ **A negative `manual_custody` row was verified safe before use:** no CHECK constraint, no
`amount > 0` filter anywhere, `sumTopups` is a plain `SUM`, and `/custody` renders it correctly.

#### 7.2.7 — ⚠️ Five older verifiers asserted the rule that was just reversed

**This is the documented lesson repeating: run the whole module's verifiers after any activation,
not just your own.** All five failures were correct — they were guarding v42 — and each expectation
was flipped deliberately, never relaxed:

| Verifier | What it asserted | Fix |
|---|---|---|
| `verify_v42` (3) | "rep spending debits the safe" — the rule itself | Assertions made era-aware; its **structural** checks ("no hand-written `paid_by_rep_id` condition") were kept and matter more than before |
| `verify_v35` (5) | same, plus the item appearing in the review's cash section | Era-aware; under the new rule it asserts the item moved to `rep_wallet` and is **absent** from the cash section |
| `verify_rep_custody_day` (1) | same | Era-aware |
| `verify_unpaid_stages` (2) | seeded **one** rep-paid item and asserted it hit **both** the safe and the rep wallet | Split into two items — an office one for the safe, a rep one for the wallet. The single item conflated two variables; the section's actual subject is `unpaid`, not `paid_by_rep_id`. Also replaced a hard-coded `700` with the v50 parity assertion |
| `verify_custody_epoch` (2) | السيد's 2026-07-29 deposits == 21,000 | Now scoped to `source='bank'`, since the Q4 `carry` rows legitimately share that date |

**Rule of thumb this produced:** an assertion that hard-codes a fixture's amount, or that measures
two wallets through one seeded row, will fail falsely the moment anything legitimate is added.
Assert the *invariant* (parity, conservation), not the *arithmetic of the current fixtures*.

#### ⚠️⚠️ Highest-risk change in this plan

This reverses the v42 decision. **The date bound is mandatory:** the new rule applies only to
`entry_date >= 2026-07-29`. Closed days keep their frozen figures in `daily_closings` and are never
recomputed. Reviewer: check `Closing::sumExpenses`, `Closing::movementTotals`,
`Closing::hasMovementBetween`, `Closing::earliestMovementDate`, `DailyReview`,
`DailyClosingController` and `bin/backfill_closings.php` all apply the same bound from one shared
helper — **not** hand-written in seven places.

**Also verify:** `Closing::refreshLockedChainFrom()` (free-edit mode) recomputes closed days. It
must recompute them with the **pre-epoch** rule for pre-epoch dates, otherwise a single free-edit
action silently rewrites locked history under the new rule.

---

### Phase 3 — Driver custody charged to the paying wallet

- [x] **3.1 — DONE, deployed 2026-07-30. Verifier 51/0 on production.**
      "مين سلّم السائق؟" now appears beside the custody field in the in-permit waybill form,
      defaulting to **🏦 من خزنة المكتب**.
      - [x] `rep_custody_select()` in `helpers.php` — server-rendered, no script (the permit
            timeline is re-rendered over AJAX), returns `''` before migrate_v60
      - [x] `PermitController::custodyPayerId()` — the guard: **the form is not a source of
            truth**; anything that is not an *active* rep falls back to NULL (office safe), and
            zero custody is always NULL
      - [x] Wired into **both** write paths (`createBill` + `updateBill`); assigned *after*
            `Calc::bawala` because it is a tag, not a calculation input
      - [x] `app/Views/permits/_movement.php` renders it
      - [x] Verifier §⑨ grew to 15 checks

      **The decisive test — both directions move together.** Tagging a real bill to a rep raises
      the safe and the rep wallet by the *same* amount; clearing the tag reverses both exactly.
      Excluding from the safe without crediting a rep would make money evaporate, and that is now
      guarded. Edit moves the money rather than duplicating it: the payer change rides in the same
      `UPDATE`, so it can never be counted twice or lost from both.

      - [x] **`/bawalis` half — also done and deployed. Verifier 56/0.**
            `app/Schema.php` gains `custody_rep_id` (type `rep_payer`, group `revenue`),
            `app/Views/crud/form.php` renders it, and `CrudController::collect()` gained a
            `rep_payer` case carrying the **same** active-rep guard as `custodyPayerId()`.

            ⚠️ `collect()` **skips the field entirely when `RepBillCustody::ready()` is false** —
            without that, every waybill save would break before migrate_v60 because the column
            does not exist.

            ⚠️ Guard proven behaviourally over six inputs (0 · empty · non-existent id · random
            text · negative · a real active rep): everything that is not an active rep falls back
            to the office safe. Storing a bogus id would exclude the custody from the safe while
            crediting nobody — money evaporating with no visible error.

#### 3.1 — implementation notes (kept for reference)

**Why this is the most urgent remaining item:** until it ships, a rep who hands a driver his
custody today still has it charged to the **office safe**, and the exact gap that Phase 1 just
cleared starts re-accumulating from this moment.

`custody_rep_id` is currently referenced **only** in `RepBillCustody`, `RepCustody` and
`Custody::capError`. It appears in **neither `app/Schema.php` nor any controller**, so nothing
writes it today outside the unused `/m/bill/new` path.

Touch points (verified by grep):

| Layer | File | Change |
|---|---|---|
| Helper | `app/Core/helpers.php` | New `rep_custody_select($name, $value, $class, $form)` — mirror `visa_card_select()` exactly: **server-rendered, no script** (the permit timeline is re-rendered over AJAX), always visible, empty state links to the reps page |
| Schema | `app/Schema.php` | Add `custody_rep_id` next to the existing `custody` field (line ~74, group `revenue`) with a dedicated `type` so `crud/form.php` renders the helper |
| CRUD write | `CrudController::collect()` | Confirm the new key is collected; **NULL when "office safe" is chosen** (never 0) |
| Permit write | `PermitController::createBill()` (~1447) and `updateBill()` (~1575) | Persist the field on both paths — missing one leaves the in-permit form silently ignoring the choice |
| View | `app/Views/permits/_movement.php` | Render the selector beside the custody input |
| Guard | `Custody::capError()` | Already skips the safe cap for rep-paid bills — re-verify after the form can set it |
| Verifier | `bin/verify_custody_epoch.php` §⑨ | Extend: choosing a rep moves **only** the rep balance; choosing the safe moves **only** the safe; the two amounts are equal for identical input |

⚠️ **The two directions must land in the same commit.** Excluding a bill from the safe without
adding it to a rep wallet makes money evaporate. `RepBillCustody::officeExcludeSql()` stays the
single source; `bin/verify_rep_bill.php` fails if any safe-side file writes the column by hand.

⚠️ **Editing an existing bill must move the money, not duplicate it.** Changing the payer from
safe → rep has to debit the rep *and* credit the safe in one step; `updateBill` currently has no
such path. Test: flip the payer back and forth and assert both balances return to their start.
- [x] **3.2** **Fixed the `100` rule on the rep path** — deployed 2026-07-30, verifier **42/0**.
      - [x] `RepBillCustody::totalFor` / `totalsByDay` / `totalsByRep` / `totalAll` now use
            `Custody::deductionSql()` instead of `SUM(custody)` — the **same expression that
            computes the office-safe deduction**, exemptions included
      - [x] `RepCustody::spending()` shows `Custody::billDeduction()` for the `bill` row (it was
            printing the gross custody, so the statement would have contradicted the balance);
            the gross is kept alongside as `gross_custody`
      - [x] Rewrote the contradicting doc block at the top of `RepBillCustody.php`
      - [x] Verifier §⑨ asserts: no raw `SUM(custody)` survives · the shared source is called ·
            tagging a real bill moves the rep balance by exactly `custody − 100` · the spending
            statement prints the same figure

      **Zero live impact:** `custody_rep_id` still has 0 rows on production, so this is a
      correctness fix landed *before* 3.1/3.3 start writing that column. Safe 67,710 and
      outstanding 81,000 both unchanged after deploy.
- [x] **3.3 — DONE.** `/m/bill/custody` — "سلّمت السائق عهدته" bound to an **existing waybill**.
      Reached from `/m` ("سلّمت سائق عهدة") and shown only when `RepBillCustody::ready()`.
      - [x] `RepPortal::claimableBills()` / `claimBillCustody()` / `unclaimBillCustody()`
      - [x] `RepPortalController::billCustodyForm/Store/Unclaim`; routes
            `GET|POST /m/bill/custody` + `POST /m/bill/{bid}/unclaim`, **literal before
            `/m/bill/{bid}/delete`** (warning #4)
      - [x] `app/Views/m/bill_custody.php` + `.bc-*` classes in **`m.css`** (the portal never
            loads `app.css` — the `.table-mobile-scroll` lesson)

      **⚠️⚠️ The single most important design decision: this screen is a TAG, not an amount
      entry.** There is **no amount field at all**. The list only offers waybills whose custody
      the office already recorded (`custody > 0`); the rep says *who paid*, nothing else.
      Reason: `bawalis.custody` sits inside the **client-debt formula** (`Calc::bawala`:
      freight − custody − transfer − discount + overnight), so one mistyped digit would change a
      client's account. As a pure tag the action is also **perfectly reversible** — untagging
      restores the previous state byte-for-byte, which an amount edit never could. The verifier
      fails if `name="custody"` ever appears in the view.

      **⚠️ Why 3.3 was the urgent one:** v60 only allowed *creating a brand-new waybill*, which
      is why `custody_rep_id` had **zero** rows — the office had normally created the waybill
      already. Until this shipped, every driver custody a rep paid kept hitting the **office
      safe**, i.e. the exact 623,605 gap Phase 1 had just cleared was re-accumulating daily.

      Guards, all **inside the model** (`claimBillCustody`), not in the UI:
      `assertAssigned` (permit must be open — v43) · `RepBillCustody::payerBlock` (epoch) ·
      `RepCustody::dayLockReason` (closed day) · `custody > 0` · not already tagged ·
      and `UPDATE … WHERE custody_rep_id IS NULL` so two reps cannot tag the same waybill.
      The rep row is locked `FOR UPDATE` — the same mutex `createBill`/`RepInbox::approve` use.

- [x] **3.3b — a real leak in 3.1 found and closed: `RepBillCustody::payerBlock()`.**

      **⚠️⚠️ The two directions are filtered differently, and that is the bug.** The safe side
      (`officeExcludeSql`) has **no** epoch filter; the wallet side (`totalFor` etc.) passes
      through `RepCustody::epochSql('bill_date')`. So tagging a waybill dated **before** the
      cutoff removed it from the safe and added it to **no** wallet ⇒ **money evaporating with
      no visible error** — the exact v45 visa hazard. As shipped, 3.1 let both office screens do
      this. `payerBlock()` is now the single source and is called from **all three** write paths
      (`PermitController::custodyPayerId` — which now receives `bill_date` in both create and
      update — `CrudController::collect`'s `rep_payer` case, and the rep portal).
      `collect()` also now refuses a payer when `custody` is 0, matching `custodyPayerId`.

- [x] **3.4 — DONE.** `bin/verify_custody_epoch.php` §⑩ (**83 checks total, 0 failures**), with
      seeded fixtures inside a rolled-back transaction:
      tagging raises the safe **and** the rep wallet by the *same* `custody − 100` ·
      `custody` and `debt` are byte-unchanged · untagging reverses both exactly ·
      duplicate tag refused · closed permit refused · pre-epoch waybill refused **and absent
      from the list** · the screen renders with real rows, carries CSRF + the one-shot token,
      and never shows a pre-epoch waybill · every `.bc-*` class has CSS.

      ⚠️ **The v49 rejection-reason trap hit again.** On real data 2026-07-01 is a closed day, so
      `Closing::guard` refuses the tag *too*. Asserting only "it was refused" passes without ever
      exercising the new guard. The check now requires the message to contain «بداية النظام
      الجديد» — and an honesty test (guard stubbed to `if (false)`) confirms **2 checks fail**.

      ⚠️ **`icon('archive')` did not exist**, so Phase 1.5's archive panel was rendering an empty
      circle **silently** (caught by `verify_typography` §⑦). Changed to `folder`.

- [x] **3.5 — Deployed 2026-07-30.** 11 files, all checksum-matched against production.
      `verify_custody_epoch` **88/0 on production** (5 more than the 83 local, because production
      has 2026-07-29 data so the §5 numbers section runs instead of being skipped) ·
      `verify_rep_bill` **133/0** · `/login` and `/m/login` both **200**.
      Balances **unchanged**: safe **67,710.00** · outstanding with reps **81,000.00** ·
      waybills tagged `custody_rep_id` still **0** (no data touched — pure capability).

      ⚠️ **Scoped upload again, NOT `bin/deploy.sh`** — the working tree holds **129 modified
      files** plus an untracked new `app/Views/excel_sync/` module from concurrent sessions, and
      `deploy.sh` rsyncs everything. Every one of my 11 files was diffed line-by-line against its
      production copy first and confirmed to contain **only my changes**. Same ordering as
      `deploy.sh`: backup → upload → permissions → checksum → verifier → HTTP 200.
      Backup: `database/backups/prod/pre-phase3.3-20260730-154531.sql.gz` (480K, `Dump completed`
      verified on the server **and** on the local copy).

      ⚠️ **The zsh word-splitting trap hit** (documented in CLAUDE.md for the v54 work): a
      `for f in $FILES` loop feeding `rsync` in zsh does **not** split the variable, so the
      download silently fetched **0 files** and the "is another session in here?" comparison
      reported **zero differences falsely**. Always assert the downloaded file count before
      trusting such a comparison.

      ⚠️ **Two pre-existing verifier failures are NOT from this work** and were confirmed present
      before any edit: `verify_navigation` (`manual_back` 39 vs ≤38) and `verify_typography`
      (hand-written font weights/sizes in `app/Views/excel_sync/*`, an untracked new module from
      another session, not on production).

#### ⚠️ The two directions must move together

Excluding a waybill from the safe **without** adding it to the rep's wallet makes money evaporate
(identical to the v45 visa warning). The condition lives in exactly one place,
`RepBillCustody::officeExcludeSql()`. The existing rule stands: **no safe-side file may mention
`custody_rep_id` directly**; `bin/verify_rep_bill.php` enforces this.

#### Note on the `100` (owner's ruling)

The `100` is **purely notional** — "it is left for the waybill's owner to profit from if he sells
it". The driver physically receives `custody − 100`, so the debit from **any** wallet is
`custody − 100`. Company-owned trucks and special trips are debited in full (no waybill owner to
profit).

---

### Phase 4 — Carry-forward + closing screen

> ### Q5 — [x] ANSWERED 2026-07-30 — and the answer is a **third option**, better than both
>
> 4.1 as written contradicted the owner's later 2026-07-27 ruling (*«فاضل معاه» يفضل عهدة اليوم
> بس*). Asked directly, he resolved it:
>
> > **«عهدة المندوب تبقى قابلة للتسوية داخل اليوم نفسه، ويُضاف أوبشن أنا كمالك أضغط زر ترحيل باقي
> > العهدة لليوم التاني — يعني لا تُرحَّل بشكل تلقائي.»**
>
> So: **no automatic carry-forward** (v49's daily lens stands, `verify_v49`'s guard stays), **plus a
> manual "رحّل الباقي لبكرة" button** the owner presses per rep. Explicit control instead of an
> invisible default — the same philosophy as `route` in v23 and the settle-to-zero button.

- [ ] **4.1 — REDEFINED per the answer above: manual carry-over button.**

      **⚠️ It must be net-zero cumulatively, and that is the whole design constraint.** The
      cumulative balance (`RepCustody::balance($rid)` with no day) is the financial truth and the
      basis of settlement and transfer limits. A carry-over does **not** create or destroy cash —
      it only moves which *day* holds it. So one press must record **−X on day D and +X on day
      D+1**, exactly like `rep_custody_transfers` (v46) does between reps, whose cumulative net is
      also zero. It is, literally, "a transfer from the rep to himself, dated forward".

      **⚠️ Do not implement it as a settlement + a deposit.** A `rep_custody_settlements.returned`
      row means *"he handed cash back to the office"* — which is false, the cash never moved — and
      it would permanently corrupt the settlements ledger and the "total returned" statistic. This
      is the same objection §6 raised against corrective settlement rows.

      **⚠️ Do not reuse `rep_custody_transfers`.** `RepTransfer::create` refuses a self-transfer by
      design, and the table carries one date where this needs two.

      Design (not yet built):
      - `rep_custody_carryovers` — `rep_id · from_date · to_date · amount · created_by`.
        A new table means **isolation by construction**: no safe-side file has ever heard of it,
        so there is no new sync point to forget (the v39/v45/v46 pattern).
      - Day balance becomes `deposits + transfers_in + carry_in − spent − returned −
        transfers_out − carry_out`, threaded through the **same six points** as v46:
        `balance/breakdown/cards/totalOutstanding/dashboard/spending`.
      - Button on the rep card's «اليوم» tab showing the day's remaining figure; guarded by
        `Closing::guard`? **No** — a carry-over touches no wallet but the rep's own and never the
        drawer, so it follows the v46 transfer precedent (no guard).
      - Verifier must assert: cumulative balance **unchanged** by a carry-over · day D drops to
        zero · day D+1 opens at X · the drawer is untouched · and `verify_v49`'s no-automatic-
        carry-forward guard still passes.
- [ ] **4.2** Keep the "صفّي العهدة" (settle to zero) action exactly as-is — owner explicitly
      requires it to always be available. Keep its existing guard that refuses to settle while the
      rep has pending submissions.
- [x] **4.3 — DONE, deployed 2026-07-30.** «الكاش برّه البنك دلوقتي» at the foot of `/closing`:
      drawer + one row per rep + the total, each rep row linking to their card.

      **⚠️ Why this section became necessary:** before Phase 2 the "safe" figure was a **pooled**
      number (drawer + reps' pockets), which is precisely why the owner could not count the drawer
      and reconcile it. After Phase 2 it is the **drawer alone** — so without this section he would
      see the number drop and not know where the rest went.

      **⚠️ Not one figure is recomputed here.** `Closing::walletSplit()` calls
      `Closing::currentBalance()` and `RepCustody::balance($rid)` — the same functions that feed
      every other screen — so it is impossible for this screen to contradict a rep's card or the
      dashboard. The verifier compares every row against its source to the piastre.

      **⚠️ Cumulative, not daily, on purpose.** The question "where is the cash right now?" is
      cumulative by nature (v49's rule: the cumulative balance is the financial truth, the daily
      view is a lens over it). And **negative balances pass through unclamped** — a negative means
      the office owes the rep (§10.5).

      Live on production: drawer 56,055 · ابراهيم 30,000 · السيد 29,000 · محمد جمال 17,400 ·
      عبدالرحمن 12,930 · احمد مرزوق 2,600 · **total 147,985**.

- [ ] **4.3-old (superseded by the above)** Original sketch:

```
carried forward 000  +  received today 000  =  available 000
− receipts 000  − expenses 000  − driver custody 000
= remaining 000
   split:  drawer 000 · Ibrahim 000 · El-Sayed 000 · Abdelrahman 000 …
```

- [ ] **4.4** Verifier: `carried + received − spent = remaining`, and
      `remaining == Σ(per-wallet)` to the pound
- [ ] **4.5** Deploy

**⚠️ The v49 rule being reversed:** "each day's custody is fully independent, no carry-forward"
made a rep holding 5,000 from yesterday and spending it today display as
**"received 0 · spent 5,000 · remaining −5,000"**. The underlying cumulative balance was preserved,
but **the screen was misleading** — one of the owner's original complaints.

---

### Phase 5 — Remove duplicate paths

> **⚠️ Outcome: Phase 5 needs NO code and NO schema change. Three of its four items rested on
> premises that measurement disproved — see below. Nothing was dropped.**

- [x] **5.1 — no action needed; already solved by Phase 1 + 3.3.**
      `rep_driver_handovers` (8 rows / 40,000) — measured on production, **every row is
      pre-epoch** (2026-07-22 · 2026-07-25 · 2026-07-26) and its contribution to current
      balances is **0.00**, because Phase 1.2 threaded `epochSql('handover_date')` through
      `totalFor`/`totalsByRep`. The creation screen was already removed in v60, and 3.3 is the
      replacement path. Rows stay so historical balances do not shift.
      *(The plan's claim that the table "still debits rep balances" was true before Phase 1;
      it is no longer.)*

- [x] **5.2 — nothing to consolidate. The premise was wrong.**
      The plan said `rep_entries` holds 1 row of kind `advance` (a parallel way to advance
      cash). Measured: `rep_entries` has exactly **1 row and its kind is `expense_logistic`**
      (70.00). There are **zero** `advance` rows. `rep_entries` is v21's payroll ledger
      (salary · transport · bonus · deduction) and is deliberately separate from rep custody —
      only `Rep::CASH_KINDS` (`advance` + `settlement`) touch the office safe, and both are
      currently unused. No change.

- [x] **5.3 — ⛔ REVISED: neither table is dropped. `rep_settlements` is NOT dead.**

      **⚠️⚠️ `rep_settlements` is live code with 0 rows** — it is v21's **periodic salary
      settlement** table. `RepController::settle` → `Rep::settle()` inserts into it,
      `Rep::settlement/settlements/settlementEntries` read it, `DeletionPolicy` maps
      `salary_settlements => rep_settlements`, and **`migrate_v41` added an FK
      `rep_entries.settlement_id → rep_settlements` with `RESTRICT`**. It is empty only because
      the owner has not run a periodic salary settlement yet. Dropping it would break the
      "رواتب وحسابات المناديب" module the first time he tried. **Do not confuse it with
      `rep_custody_settlements` (5 rows) — a different table.**

      **⚠️ `driver_custody` (0 rows) is dead data but still live code:**
      `Metrics::custodyDay()` reads it in a UNION branch (the custody drill-down popup), and
      `LegacyImporter` truncates it during a legacy re-import; it is also in `schema.sql` and
      `migrate_v2`. Dropping it to remove a 0-row UNION branch buys nothing and would break
      three call sites. **Left in place.**

      Verdict: an empty table is not evidence of dead code. Both stay.

- [x] **5.4** `CLAUDE.md` and this document updated with the final model and the corrections above.
- [x] **5.5** No deploy needed — no code changed in Phase 5.

---

## 8. Safety Guarantees

- [ ] **G1** Full DB backup before every phase, integrity-checked via the `Dump completed` trailer
- [ ] **G2** Every data-touching script supports `--dry-run` and prints every value it would change
      **before** changing it, for owner review
- [ ] **G3** Each phase's data changes run inside **one transaction** — all or nothing
- [ ] **G4** Each phase ships with a verifier asserting: rep balances match §5 · safe balance
      unchanged where it should be · closed days byte-identical · no submission row touched
- [ ] **G5** **No pre-cutoff row is ever deleted or edited.** Separation is achieved by a date
      filter, not by data correction.
- [ ] **G6** Execution happens while data entry is paused (early morning or after hours)
- [ ] **G7** Rollback: clearing `rep_custody_epoch` restores the previous behaviour immediately
      with no data loss. Phases 2–4 are additive-with-date-bound, so reverting the bound reverts
      the behaviour.
- [ ] **G8** Verifiers registered in `bin/run_verifiers.php` (an unregistered verifier makes the
      runner refuse to run **any** verifier)

---

## 9. Open Questions

### Q0 — Cash physically in the office drawer at end of 2026-07-28 — [x] ANSWERED

**56,780.** See §5.2. The owner's hand breakdown reconciles exactly to the stored closing balance
of 67,710.

### Q1 — [x] RESOLVED BY CONSTRUCTION (no owner answer was needed)

The cutoff mechanism dissolved the ambiguity. Because the epoch **excludes** every pre-cutoff
movement from the balance, the 2,930 / 8,000 carried in from the 28th land in the *archive*, not in
the new balance. So "top up to 10,000" and "add 10,000" converge on the same result. The full
amounts (السيد 21,000 · عبدالرحمن 10,000) were inserted, and the measured outcome is exactly the
owner's stated targets — see the §7 Phase 1 result table (عبدالرحمن **920** = 10,000 − 9,080).

### Q1-old — [ ] superseded — are the 2026-07-29 targets inclusive of the carried balance?

Two reps carry cash into the 29th (§5.2), so the two figures the owner gave for the 29th are
ambiguous:

| Rep | Carried in from the 28th | Target for the 29th | Reading A: **top up to** target | Reading B: **add** on top |
|---|---:|---:|---:|---:|
| عبدالرحمن | 2,930 | 10,000 | insert **7,070** → total 10,000 | insert 10,000 → total 12,930 |
| السيد الفنان | 8,000 | 21,000 | insert **13,000** → total 21,000 | insert 21,000 → total 29,000 |

Reading A is the more likely one — the owner phrased both as «المفروض معاه/عهدة» ("he is supposed
to have"), which reads as a total, not an increment. **Do not act on this inference; it must be
confirmed explicitly.** Getting it wrong permanently misstates two rep balances.

### Q2 — [x] ANSWERED 2026-07-30: **all three came straight from the bank. None from the drawer.**

| Deposit | Amount | `source` |
|---|---:|---|
| ابراهيم — note «عهدة من أحمد عادل» | 50,000 | `bank` |
| السيد | 21,000 | `bank` |
| عبدالرحمن | 10,000 | `bank` |

So Phase 2 can ship with **`source='bank'` as the default and all existing rows correct as-is** —
no back-tagging needed, and the drawer is untouched by any of the three. This confirms §2's core
finding: the real flow is bank → rep, and the office drawer is normally not involved at all.

### Q4 — [x] ANSWERED 2026-07-30 (outcome authorised) — ⚠️ but the mechanism must change

**Owner's decision:** *"اقفل اليوم على 56780 والعهد تتوزع في حساب المندوبين عبده والسيد ليوم 29"* —
i.e. the safe must read **56,780** (true drawer cash), and the **10,930** must land in the two reps'
accounts **for 2026-07-29** (عبدالرحمن 2,930 · السيد 8,000).

Resulting target state — and note that **total cash is conserved**, which is the proof the decision
is coherent:

```
before:  safe 67,710  +  reps 81,000  = 148,710
after:   safe 56,780  +  reps 91,930  = 148,710
                                         السيد 29,000 · عبدالرحمن 3,850 · ابراهيم 12,215 …
```

**⚠️⚠️ The literal mechanism (option C — re-close 2026-07-28 with 56,780) is NOT durable and must
not be used.** `Closing::refreshLockedChainFrom()` **recomputes `closing_balance` from the raw
movement rows** (`opening + topups − expenses − driver_custody`) for every closed day from a given
date onward, and it is invoked automatically via `scheduleFreeRefresh()` whenever free-edit mode
touches anything. So a hand-written 56,780 on the 2026-07-28 row would be **silently recomputed
back to 67,710** the first time that happens — the correction would evaporate with no error.

**Durable implementation of the same decision (option B mechanics, option C outcome):**

1. One dated `manual_custody` adjustment of **−10,930 on 2026-07-29**, note
   «كاش كان مع المناديب قبل بداية النظام الجديد — عبدالرحمن 2,930 + السيد 8,000».
   The safe then reads **56,780 from day one of the new system onward**, which is the only period
   the owner ever operates in, and **no locked day is touched** (honouring §5.1 / G5).
   The stored 67,710 on the 2026-07-28 row stays — and it is *historically accurate*, because on
   that date the figure genuinely was the **pooled** drawer+pockets number (§5.2).
2. Two `rep_custody_deposits` rows dated **2026-07-29** — عبدالرحمن 2,930 · السيد 8,000 — with a
   source that credits the rep **without** debiting the safe (the safe side is already handled by
   step 1; using `source='office'` here would double-debit).

**⚠️ This needs a third `source` value.** `bank` and `carry` have identical mechanics (no safe
effect) but different meaning, and the distinction matters for drawer reconciliation and the audit
trail:

| Source | Effect on safe | Effect on rep | Meaning |
|---|---|---|---|
| `bank` | none | + amount | withdrawn from the bank, handed straight to the rep *(the common case)* |
| `office` | − amount on the deposit date | + amount | taken out of the office drawer |
| `carry` | none | + amount | opening float carried across the cutoff — used **once**, at the cut |

3. Verify: `safe == 56,780` · `outstanding == 91,930` · `safe + reps` unchanged at 148,710 ·
   every `daily_closings` row byte-identical · and re-running `refreshLockedChainFrom` from
   2026-07-28 changes **nothing** (the durability test that option C would fail).

⚠️ Steps 1–3 must land **after** Phase 2.1/2.3, not before — the `source` column has to exist and
the reversal has to be in place, otherwise the two deposits would be interpreted under the old v42
rule and the arithmetic would come out wrong.

### Q4 — [ ] BLOCKS Phase 4 — what should "office safe" mean after Phase 2?

Not in the original plan; surfaced by §5.2 and by finishing Phase 3. **Phase 4 cannot be designed
until this is answered, because it decides what the closing screen's headline number is.**

§5.2 proved the stored closing balance is **not drawer cash** — it is
`drawer + Σ(cash in reps' pockets)` (56,780 + 2,930 + 8,000 = 67,710). Phase 2 stops debiting the
safe for rep spending, which makes the safe number *converge on drawer-only* going forward — but the
**10,930 already inside the carried-forward 67,710 stays there as phantom drawer cash unless it is
explicitly removed**.

Three options, and this is the owner's call because it changes a reported balance:

| Option | Effect | Cost |
|---|---|---|
| **A** — leave it | Safe reads 10,930 high forever; every future day inherits it | Zero work, permanent small error |
| **B** — one adjusting `manual_custody` row of −10,930 dated 2026-07-29, note "الكاش اللي كان مع المناديب" | Safe becomes true drawer cash from day one of the new system | One reviewable row; changes a reported number |
| **C** — re-close 2026-07-28 with a corrected closing balance of 56,780 | Cleanest arithmetic | **Touches a locked day** — against the spirit of §5.1 |

Recommendation: **B**. It is additive, dated, auditable, reversible, and never touches a locked day.

### Q3 — [ ] Non-blocking — بدر spent 1,647 on the 29th with zero float

Confirm this was his own money (company owes him −1,647), which is what the owner's instruction
(«ملهوش عهدة، سيب بنوده زي ما هي») implies.

---

## 10. Notes for the Reviewer (Codex)

Points worth adversarial attention:

1. **§6.1 completeness.** The epoch must reach ~35 call sites. A missed one produces a silently
   wrong number, not an error. Cross-check against `grep -rn "rep_custody_deposits\|paid_by_rep_id\|rep_misc_expenses\|rep_custody_transfers\|custody_rep_id\|rep_driver_handovers" app/`.
2. **Phase 2's date bound.** Reversing v42 without a hard date bound would silently rewrite the
   meaning of already-closed days. Confirm the bound is applied from one shared helper.
3. **`Closing::refreshLockedChainFrom()`** recomputes locked days in free-edit mode — it must not
   apply post-epoch rules to pre-epoch dates.
4. **Phase 3's two directions.** Any change that excludes a waybill from the safe must, in the same
   commit, add it to a rep wallet.
5. **Negative balances are legal.** Do not add `max(0, …)` anywhere in rep custody: بدر legitimately
   sits at −1,647, and `rep_custody_settlements.returned` accepts negatives by design.
6. **Timezone trap (v48).** Production MySQL runs UTC while PHP runs `Africa/Cairo` — a 180-minute
   offset. **Any comparison between a DB timestamp and "now" must happen inside SQL.** Date-only
   columns (`entry_date`, `dep_date`, `bill_date`, `work_date`) are unaffected and must not be
   shifted for display.
7. **PHP trap (documented, hit 6× in this codebase):** `"«$var»"` — the bytes of `«»` are parsed as
   part of the variable name. Always use `{$var}` inside Arabic strings containing brackets.
8. **Verifier honesty.** A check that passes on an empty result set (`0 == 0`) is a false pass.
   Verifiers must seed real fixtures inside a transaction and assert non-empty results before
   rolling back.

---

## 11. Data Provenance

Every figure here was measured against the **production** database on 2026-07-29/30 via direct
SQL over SSH. Key measurements:

| Measurement | Value |
|---|---:|
| Office safe balance (live) | 67,710 |
| Office safe balance at 2026-07-27 close | 17,575 |
| Reported outstanding with reps | 704,605 |
| Total `manual_custody` top-ups (all time) | 2,362,150 |
| Total rep deposits (all time) | 930,560 |
| Driver custody issued since 2026-07-20 | 690,500 (81 waybills) |
| Approved rep permit items | 192,209 (175 items) |
| Rep petty cash | 26,195 (52 items) |
| Rep→driver handovers (orphan path) | 40,000 (8 rows) |
| Waybills tagged `custody_rep_id` (v60) | **0** |
| Pending submissions before 2026-07-29 | 81,000 (6 items — all driver custody) |
| Pending submissions on 2026-07-29 | 31,037 |
| Last closed day | 2026-07-28 |

---

## ملحق (2026-07-31) — «نزّل عهدة لمندوب من الخزنة نفسها»

**طلب المالك:** «إمكانية إضافة عهدة للمناديب من الخزنة نفسها وليس خارجيًا دون تعارض».

**الوضع قبل الشغل ده (مقيس على الإنتاج):** المحرّك كان جاهز من v61 (`source='office'`)،
لكن خانة «الفلوس جاية منين؟» كانت مشروطة بـ`newSafeRuleActive()` **لوحده**، والإنتاج
شغّال بنموذج «الخزنة = كل كاش الشركة» (`rep_bank_topup_from` فعّال · `rep_safe_rule_from`
فاضي) ⇒ **الخانة مخفية** وكل تنزيل بيتسجّل `bank` غصبًا وبيزوّد الخزنة. يعني الكاش اللي
خرج من الدرج كان بيتسجّل على إنه فلوس جديدة داخلة — **يتعدّ مرتين**.

**اللي اتعمل (بلا هجرة، وبلا أي تغيير في معادلة موجودة):**

| # | التغيير | المصدر الوحيد |
|---|---------|----------------|
| ١ | الخانة بتظهر في **أي** نموذج المصدر بيفرق فيه | `RepCustody::sourceMatters()` |
| ٢ | حارس اليوم المتقفل بيتبع **الأثر** مش اسم المصدر (إنشاء + حذف) | `RepCustody::depositAffectsSafe()` |
| ٣ | شرح واحد للشاشتين | `RepCustody::sourceNote()` |
| ٤ | نقطة دخول من `/custody` — المصدر مثبَّت `office` في الكنترولر | `POST /custody/rep-deposit` |

**جدول الحقيقة (محروس في الفاحص):**

| النموذج الفعّال | `sourceMatters` | `office` يمسّ الخزنة | `bank` يمسّ | `carry` يمسّ |
|---|---|---|---|---|
| مفيش | false | ✗ | ✗ | ✗ |
| المرحلة ٢ (`rep_safe_rule_from`) | true | ✓ مصروف | ✗ | ✗ |
| كل كاش الشركة (`rep_bank_topup_from`) — **الفعّال** | true | ✗ تحويل داخلي | ✓ إضافة | ✗ |
| الاتنين | true | ✓ | ✓ | ✗ |

**فحص:** `php bin/verify_rep_deposit_source.php` (٤٢ فحص — جدول الحقيقة · المصدر الواحد ·
حفظ الفلوس · حارس اليوم المتقفل · رندر الشاشتين). اتشغّل محليًّا وعلى الإنتاج: ٤٢/٠.
رصيد الخزنة قبل = بعد = **57,740** (درج 8,850 · مناديب 48,890).

**⚠️ مصيدة موثَّقة:** «كل الكاش برّه البنك» ليه **تعريفان** حسب النموذج — اقراه من
`Closing::walletSplit()` لا تجمعه بإيدك، وإلا جيوب المناديب تتعدّ مرتين في النموذج المجمّع.

### تكملة — ظهوره في التقفيل اليومي (نفس اليوم)

**طلب المالك:** «تظهر ككارت في التقفيل اليومي».

**اللي كان حاصل:** قسم `rep_deposit` في `DailyReview::collect()` كان مشروطًا
بـ`newSafeRuleActive()`، وقسم عهد البنك (⑤ج) **بيتخطّى `office`** — فالنموذج
المجمّع (الفعّال) مكانش بيعرض الكاش الخارج من الدرج **في أي مكان** في `/closing`،
رغم إنه بيقلّل الدرج اللي المالك بيعدّه بإيده.

**الحل:** قسم مرآة `rep_drawer` «نزلت للمناديب من الدرج» — التوأم المعكوس
لـ`rep_settle` («استلمت من المناديب»).

| النموذج الفعّال | القسم | النوع | مانع للإقفال؟ | الشرط |
|---|---|---|---|---|
| المرحلة ٢ | `rep_deposit` | نقدي | ✓ | `RepCustody::officeDepositSql()` (بحدّ خط الفصل — **نفس شرط `Closing`**) |
| كل كاش الشركة | `rep_drawer` | معلوماتي | ✗ | `RepCustody::sourceIsSql('office')` (عرض بحت بلا حدّ) |

**⚠️ قواعد لازم تفضل:**

- **`rep_drawer` ممنوع يدخل `BLOCKING`** — لو دخل، مجموع الأقسام النقدية هيخالف
  `Closing::dayView` وقاعدة تطابق v50 تتكسر بلا أي خطأ ظاهر.
- **مفتاح إجمالي مستقل** `totals['rep_drawer']` (الخامس بعد visa/unpaid/rep_wallet/rep_settle)
  — في فرع `else` كان هيتقري «مدفوعات فيزا».
- **`item_type` بيفضل `rep_deposit` في النموذجين** — علامات المراجعة مخزَّنة بـ(type,id).
- **ما تخلطش `sourceIsSql` بـ`officeDepositSql`**: الأولى «مصدره الدرج»، والتانية
  «كاش خرج من الدرج **ويتحسب مصروفًا**». و`sourceIsSql` بترجّع ` AND 0` (مش `''`)
  لأي مصدر غير `bank` قبل migrate_v61.

**فحص:** `bin/verify_rep_deposit_source.php` بقى **٥٧ فحص** (محليًّا وعلى الإنتاج،
صفر فشل) + `verify_v50` (١٠٢) + `verify_closing_redesign` + `verify_safe_rule` نضاف.
الخزنة قبل = بعد = **57,740**.

### تصحيح 2026-08-01 — كارتا العهد في التقفيل

**بلاغ المالك:** «العهد لا تظهر في صفحة التقفيل في كارت العهد المضافة. المفروض
يظهر كارت العهد المضافة العهد التي تم إضافتها من البنك + كارت آخر للعهد اللي
طلعت من الدرج».

**كان حاصل إن** تحويل «الخزنة = الدرج بس» خلّى عهد البنك تتنقل لكارت معلوماتي
مستقل، فالمالك يفتح يومًا زي 2026-07-30 (فيه ٤ عهد بنك و٣ عهد درج) ويلاقي كارت
«العهد المضافة» **مش موجود خالص** (مفيش `manual_custody` في اليوم ده).

**الشكل النهائي — كارتان زي ما طلب:**

| الكارت | المحتوى | النوع |
|---|---|---|
| **العهد المضافة** | إضافات الدرج اليدوية + العهد من البنك للمناديب | نقدي، مانع |
| **عهدة نزلت للمناديب من الدرج** | العهد اللي طلعت من الدرج | نقدي، مانع |

**⚠️ الاسم اتغيّر من «عهدة مضافة للخزنة» لـ«العهد المضافة»** (كلام المالك نفسه):
الكارت بقى فيه عهد بنك **ما دخلتش الخزنة** في النموذج ده، واسم فيه «للخزنة» بيكدب
(يوم ٣٠: الكارت ٤٤,٠٠٠ والداخل للدرج **صفر**). وكمان المعادلة فوق اسمها «عهدة
مضافة للدرج» فالاتنين كانوا هيتلخبطوا.

**⚠️⚠️ `DailyReview::countedTopupTypes()` — المصدر الوحيد لـ«أنهي بند بيتحسب في
الرصيد».** الكارت بيعرض الكل، لكن `Closing::sumTopups` مش بيحسب الكل:

- `topup` ⇒ محسوب دايمًا · `rep_carry` ⇒ عمره ما يتحسب
- `rep_bank` ⇒ محسوب **بس** في نموذج «كل كاش الشركة»

**ممنوع تكتب القايمة دي بإيدك في فاحص أو شاشة** — القايمة الثابتة `['topup','rep_bank']`
كانت هتنجح في نموذج وتفشل في التاني. `verify_v50` اتحدّث ليقراها من هنا.

**فحص:** `verify_rep_deposit_source` (٦٧) · `verify_v50` (١٠٢) · `verify_closing_redesign`
· `verify_safe_rule` · `verify_custody_epoch` · `verify_v42` · `verify_v49` — كلهم
صفر فشل محليًّا وعلى الإنتاج.

### معادلة التقفيل — خانة «عهدة مسحوبة من الدرج» (2026-08-01)

**طلب المالك:** «الكروت اللي فوق تبقى: رصيد أول اليوم + عهدة مضافة للدرج =
الإجمالي − عهدة مسحوبة من الدرج − مصروفات نقدية − عهد السائقين = الصافي المرحل للغد».

**كان حاصل إن** الكاش اللي بينزل من الدرج لجيب مندوب **مدفون جوّه «مصروفات نقدية»**
(`Closing::sumExpenses` بيضمّه عبر `sumOfficeDeposits`)، فالمالك يشوف رقمًا كبيرًا
ومايعرفش منه كام راح لمندوب وكام اتصرف فعلاً.

**⚠️⚠️ الخانة الجديدة رقم موجود اتفصل — مش رقم زيادة.** المصدر الوحيد
`Closing::dayOfficeDeposits()` (بيلفّ `sumOfficeDeposits` نفسها)، والكنترولر
**بيطرحه من `totExpense`**. من غير الطرح ده بيتجمع مرتين والمرحّل يطلع غلط بلا أي
خطأ ظاهر. المعادلة النهائية:

```
opening + topups − officeDep − expenseCash − driver = closing
```

**⚠️ بيتحسب حيًّا حتى لليوم المتقفل** — `daily_closings` مفيهاش عمود للجزء ده،
وصفوف `rep_custody_deposits` ما بتتغيّرش بعد الإقفال فالمشتق مطابق للمخزَّن.

**⚠️ الخانة بتظهر بس مع `newSafeRuleActive()`** — في النموذج المجمّع ده تحويل داخلي
مالوش أثر، وخانة بصفر في معادلة تشتيت.

**⚠️ ثلاث نقاط لازم تمشي مع بعض:** الخانة في `closing/index.php` · نافذتها
(`#drillOfficeDep` بقسم `rep_deposit`، و`$expKeys` اتشال منها `rep_deposit`) ·
وصفحة الطباعة (سطر مستقل + `$totOutflow` بيجمعه) — وإلا الورقة تطلع بمعادلة **مش مقفولة**.

**متحقَّق على الإنتاج:** يوم ٣٠/٧ `29,770 + 0 − 15,850 − 0 − 0 = 13,920` ✓ ·
يوم ٢٩/٧ `67,710 + 27,565 − 0 − 57,505 − 8,000 = 29,770` ✓.
`verify_rep_deposit_source` بقى **٧١ فحص** (فيهم فحص «المعادلة بتقفل») + ٧ فاحصات
تانية، كلهم صفر فشل محليًّا وعلى الإنتاج.

### 2026-08-01 (لاحقًا) — «عهدة منزلة للمناديب» خانة محسوبة + سطر التحويل الداخلي

**طلب المالك:** «كارت عهدة مضافة للدرج يتغيّر ويبقى عهدة منزلة للمناديب، ويتحط
فيه الـ٤٤,٠٠٠ بتوع يوم ٣٠، **ويتحسبوا مع الحسب**» — ثم اختار «الاتنين» (السطرين
مع بعض) لما اتعرضت عليه البدائل.

**⚠️⚠️ ده رجوع للنموذج المجمّع.** «يتحسبوا» تعني إن عهد البنك تدخل الرصيد، وده
ممكن **بس** لو الرقم النهائي = **كل كاش الشركة** (الدرج + جيوب المناديب). فاتنفّذ
`php bin/activate_drawer_only.php --off` (بنسخة احتياطية قبله)، ورجع
`rep_bank_topup_from = 2026-07-29` و`rep_safe_rule_from = ''`.
**نموذج «الدرج بس» بقى موثَّقًا ومجرَّبًا، والرجوع له أمر واحد.**

**المعادلة النهائية على الشاشة والورق:**

```
رصيد أول اليوم + عهدة مضافة للدرج + عهدة منزلة للمناديب = الإجمالي
⇄ نزل من الدرج للمناديب (تحويل داخلي — بيتعرض ولا يتطرح)
− مصروفات نقدية − عهد السائقين = الصافي المرحّل
```

**⚠️⚠️ ثلاث دوال متشابهة الأسماء وممنوع تتخلط:**

| الدالة | معناها | بتدخل الحساب؟ |
|---|---|---|
| `Closing::dayBankDeposits()` | عهد البنك للمناديب — **جزء من `topups`** | ✓ في النموذج المجمّع |
| `Closing::dayOfficeDeposits()` | عهد الدرج **اللي بتتحسب مصروفًا** — جزء من `expenses` | ✓ في نموذج الدرج بس |
| `Closing::dayDrawerToReps()` | **كل** عهد الدرج — **للعرض بس** | ✗ أبدًا |

الأولى والتانية **بتتطرح من خانتها الأصلية** في الكنترولر (`topups − bank`،
`expenses − office`) — من غير الطرح الرقم بيتعدّ مرتين والمعادلة ما تقفلش.
والتالتة موجودة عشان المالك يشوف السطر حتى في النموذج اللي مابيخصمهوش.

**⚠️ الخانة اللي بتظهر بتتبع النموذج تلقائيًّا:** في المجمّع `dayOfficeDeposits`=0
فبتظهر علامة `⇄` المحايدة، وفي «الدرج بس» `dayBankDeposits`=0 فبتظهر `−`. **مفيش
شرط مكتوب بالإيد في الشاشة** غير `newSafeRuleActive()` اللي بيختار الشكل.

**⚠️ نافذة التفاصيل مش مشروطة بالنموذج** — القسم اللي بيغذّيها بيتغيّر
(`rep_deposit` في الدرج بس · `rep_drawer` في المجمّع)؛ تثبيت واحد منهم كان بيخلّي
الزرار يفتح نافذة **فاضية**.

**متحقَّق على الإنتاج:** يوم ٣٠ `29,740 + 0 + 44,000 − 0 − 8,000 = 65,740` ✓ ومعاه
`⇄ 15,850` · يوم ٢٩ `67,710 + 0 + 91,000 − 107,970 − 21,000 = 29,740` ✓.
`verify_rep_deposit_source` بقى **٧٩ فحص** + ٧ فاحصات تانية، كلهم صفر فشل محليًّا
وعلى الإنتاج.

### 2026-08-01 — إضافة مصاريف الإذن للفاتورة (اختيارية)

**طلب المالك:** «عند إصدار الفاتورة يكون في أوبشن إضافة بنود من المصاريف الخاصة
بالإذن نفسه — اختيار عرض بنود المصاريف ووضع أوبشن لإضافة شيء منهم».

**الخلفية:** قرار v20 شال السحب التلقائي لمصاريف الإذن (`stage_key='custom'`,
`direction='expense'`) من الفاتورة، لأن منها اللي بيتحمّله المكتب ومنها اللي
بيتحاسب عليه العميل — والسحب الأعمى كان بيحمّل العميل حاجات مش بتاعته. القرار ده
**ما اتغيّرش**؛ اللي اتضاف هو **العرض والاختيار**.

**الشكل:** لوح `<details>` مطوي تحت جدول البنود في `permits/invoice_form.php`،
بيعرض كل مصروف (البيان · التاريخ · الكمية × سعر الوحدة · الإجمالي) وزرار «أضف
للفاتورة». البند المتضاف بيتقفل زرّاره ويتعلّم `is-added`.

**⚠️ نقاط الأمان والمال:**

- البند بيتضاف بـ`item_src = stage:{id}` — **نفس مسار بنود الإيصالات بالحرف**،
  فمفيش منطق حفظ جديد.
- المزامنة العكسية عند الإصدار بتحدّث **`note/qty/actual_amount` بس** —
  `amount` النقدي ما بيتمسّش، فالخزنة والتقفيل المثبَّت ما يتأثروش (قاعدة v20).
- التحديث محكوم بـ`AND permit_id = ?` — الفورم مش مصدر ثقة.
- البيان بيتحطّ بـ`.value` مش جوّه `innerHTML` (جاي من إدخال المستخدم).
- المصروف بلا `actual_amount` بياخد تنبيه «بسعر التكلفة — مفيش سعر فعلي للعميل».
- الكلاسات في `app.css` مش `<style>` محلي (الجافاسكريبت بيحقن `is-added`).

**فحص:** `bin/verify_permits_ui.php` قسم **⑦ج** (١٠ فحوص، فيهم رندر فعلي لإذن فيه
١٥ مصروف). **⚠️ مصيدة في العدّ:** `js-add-exp` بيتكرر في الجافاسكريبت كمان، فالعدّ
لازم يكون على `js-add-exp"` بعلامة التنصيص المزدوجة وإلا بيزيد ١ ويفشل بإنذار كاذب.
