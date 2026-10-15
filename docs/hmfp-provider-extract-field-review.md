# HMFP Provider Extract: Field Review for the Expertise Search Tool

_Prepared 2026-10-02 for the export ticket._

**Sources compared**

- `hmfp_providers_and_affiliations__1002206_provider.csv`: 2,322 providers, one row each
- `hmfp_providers_and_affiliations__1002206_affiliations.csv`: 8,503 rows, one per provider per facility
- The original Kyruus extract (`sample-provider-demographics.csv`) and the current importer (`ImportPhysiciansCommand`)

**Overlap:** 2,120 of the new export's NPIs also appear in the Kyruus sample. About 200 are new, and those are probably mostly advanced-practice providers.

---

## What's in the new export

**Provider file:**
`NPI, LastName, FirstName, MiddleName, FieldOfLicensure, PrvType, Gender, HMFPInd`

**Affiliations file:**
`NPI, AffilAbbrv, FacilityName, IsActive, ClinicalTitle, AffilCategory, PCPSpecialistInd, StaffType, AffilStatus, InitialAppointmentDt, MecApprovalDate, AdmittingPrivilege, ResignedDt, HiringGroup`

---

## 🔴 DEFINITELY NEEDED, and missing from the new export

| Field | Why |
|---|---|
| **`cred_id`** | The importer's entire identity system is built on it, and every existing physician row already has one. Without it, the importer would have to be reworked to key on NPI. That's doable, since NPI is 100% filled and unique, but adding one column is far simpler. |
| **Specialty** | Main search filter. Not in the new file. |
| **Department** | Department filter. Not in the new file. |
| **Degree / credentials** | `PrvType` covers most of it (MD, DO, PA…), but it holds a single value, so an "MD, MPH" would lose the MPH. Need the full degree string. |

## ✅ DEFINITELY NEEDED, and present

| Field | Notes |
|---|---|
| `NPI` | 100% filled and unique. The claim flow needs it. |
| `FirstName` / `MiddleName` / `LastName` | Fine, apart from the `NULL` issue below. |
| `FacilityName` + `AffilAbbrv` | Better than Kyruus. `AffilAbbrv` is a stable facility code (BIDMC, BIDN, MAH…), so a renamed facility won't turn into a duplicate. |
| `IsActive` / `ResignedDt` | Covers the "active/status flag" need. Only active affiliations would be linked, so a departure no longer has to be inferred from someone vanishing out of the file. |

## 🟡 NICE TO HAVE, still missing (carry over from Kyruus)

- `preferred_full_name`
- `languages`
- `preferred_phone_number`

(Gender **is** present in the new file.)

## 🟢 NEW AND USEFUL, in this file

| Field | Possible use |
|---|---|
| `ClinicalTitle` | Display on the profile ("Chief of Podiatry", "Interim Chair, Emergency Medicine"). Good expertise signal. |
| `PCPSpecialistInd` | A "primary care vs specialist" filter. |
| `HMFPInd` | Separates HMFP from APHMFP (see decisions below). |
| `FieldOfLicensure` | A readable label ("Physician (MD)", "Nurse Anesthetist"). |

## 🟢 NOT INCLUDED BUT USEFUL (still worth asking for)

1. **Board certifications.** The `board_certification` vocabulary exists, but nothing feeds it.
2. **Conditions treated / procedures performed.** The `condition` and `procedure` vocabularies exist and are free-text searchable, but they have no data source. For an expertise search, these two matter most.
3. **Facility address and lat/long.** Could come as a small separate facility file keyed on `AffilAbbrv`. Facility addresses are currently entered by hand and haven't been verified.
4. **Department code (MD Staff code).** A stable department ID, so a renamed department doesn't look like a brand-new one.
5. **Work email or network/SSO ID.** Would let a signed-in physician be matched to their profile automatically.
6. **Primary specialty and primary location flags.** Help with result ordering.

## ⚪ NOT NEEDED

`StaffType`, `AffilStatus`, `AffilCategory`, `InitialAppointmentDt`, `MecApprovalDate`, `AdmittingPrivilege`, `HiringGroup`

These are credentialing housekeeping and don't help search. (`ResignedDt` is only useful alongside `IsActive`.)

From the Kyruus file, these can also be dropped: `provider_is_employed` (100% empty), `image_url` (100% empty; it becomes useful if it can actually be filled), `virtual_care`, `online_booking_url`, `bilhpn_provider`, `suffix`.

---

## ❓ Open decisions

1. **Advanced-practice providers.** 681 of the 2,322 are `APHMFP`: CRNAs (174), NPs, PAs, CNMs, plus some PhD/PsyD. Should an expertise search include them, or only `HMFP`?

   | PrvType | Count |
   |---|---|
   | MD | 1,964 |
   | CRNA | 174 |
   | DO | 74 |
   | NP | 33 |
   | PA | 31 |
   | PhD | 16 |
   | CNM | 11 |
   | DPM | 9 |
   | PsyD | 5 |
   | DNP | 2 |
   | MSN, DDS, NULL | 1 each |

2. **Affiliations that aren't places.** The biggest "facility" is `BIDCO — Physician Performance`, with 2,139 active rows, and there are also `BILHPN`, `MACIPA` and `NEPHO`. Those are networks or contracting entities, not buildings, and distance search would treat them like locations. Either ask for a "location vs network" flag or keep a skip list of codes on our side. (The current Kyruus import has the same problem.)

---

## 📄 Format requests

- **Literal `NULL` strings.** `MiddleName`, `Gender`, `ClinicalTitle` and others contain the text `NULL`. Please send empty cells instead.
- **Ambiguous dates.** The CSV has `14/04/2020` (day first) and the xlsx has Excel serial numbers (`43935`). Please use ISO 8601: `2020-04-14`.
- **Comma-delimited with commas in the data.** Values like `Interim Chair, Emergency Medicine` only parse correctly if quoted. Pipe-delimited (like the Kyruus extract) avoids the problem.
- **One list separator everywhere.** If multi-value cells come back (specialty, department, languages), please use `;` for all of them.
- **No trailing empty columns**, UTF-8, and stable header names.

---

## Bottom line

This export is a good **roster and affiliation** feed, but it can't replace the Kyruus extract on its own.

**Cleanest ask:** this file, plus `cred_id`, full degree, specialty, department, preferred name, languages, and phone.

**Fallback:** keep both feeds and join them on NPI. The importer would then read two or three files: provider rows first, then affiliations attached by NPI.
