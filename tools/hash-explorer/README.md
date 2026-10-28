# MedConnect Hash Explorer

A standalone learning tool for understanding password hash formats, SQL seed variables, text digests, and safe password verification. It is separate from MedConnect's authentication and database code and has no third-party network calls or database connection. SHA digests, MD5, and PBKDF2 run locally in the browser. An isolated PHP helper enables bcrypt and, if available in that PHP build, Argon2id.

## Launch locally

Open `index.html` directly in a modern browser, or serve this folder from a local PHP server. Direct file mode supports browser digests and PBKDF2; bcrypt and Argon2id require the local PHP helper. For example, from the repository root:

```powershell
php -S 127.0.0.1:8088 -t tools/hash-explorer
```

Then open <http://127.0.0.1:8088>. From the `tools/hash-explorer` directory, the same command works without `-t`.

## Use

- **Hash Analyzer:** paste one hash and select **Analyze hash** (or press Ctrl/⌘+Enter). Format recognition is a heuristic. It cannot identify an unknown password or prove the algorithm's provenance. **Copy hash** only copies after the user clicks it. **Reset** clears the input. Use the download icon in the top bar to export the current analysis as a text report.
- **Hash Types:** compare eight algorithms and their output shapes, intended purposes, password suitability, risks, and typical uses.
- **Text to Hash:** generate MD5 and SHA-1 (educational/compatibility only), SHA-256/384/512, salted PBKDF2-HMAC-SHA-256, and local PHP bcrypt / Argon2id when available. A blank input is valid text and produces the algorithm's empty-input result. PBKDF2 iterations are configurable from 100,000 to 2,000,000. Password KDF results include random salts, so repeated runs differ.
- **Hash Verification:** compare digest algorithms locally, verify PBKDF2 using its encoded salt and parameters, or use PHP's `password_verify()` for bcrypt / Argon2id. The PHP selector is checked against the encoded hash prefix. A deterministic SHA digest is not an acceptable password-storage substitute.
- **Exporting generated results:** downloading includes both original text and generated hash and requires confirmation. Do not export data you consider sensitive.
- **SQL Hash Variables:** paste seed SQL and select **Inspect SQL**. It recognizes common `SET @variable = ...` and `INSERT INTO table (columns) VALUES (...)` patterns. It parses text only and never executes SQL. The parser is intentionally limited; complex SQL may not be recognized. Rendering uses escaped text.
- **Password Verification:** copy the PHP example. `password_hash()` should create the saved hash; `password_verify()` checks a candidate against that stored hash. Bcrypt carries its salt in the encoded hash, so do not compare a newly generated hash string directly. In a real application, initialize `$password` from the candidate input and `$storedHash` from the account record retrieved safely.
- **Theme and navigation:** use the sun/moon button for dark/light mode. Navigation is keyboard reachable; the mobile menu button opens the sidebar.

## Supplied bcrypt example

`$2y$10$sVtK1y05Jsk4hSsG5krjUOcDJW3c4loF13byj7Up.Z8fFoDdzxe1S` matches the common 60-character bcrypt modular-crypt shape. `$2y$` is commonly used by PHP for bcrypt, and `10` is the work factor (2^10 setup rounds). The remaining encoded content contains the salt and derived password-hash data in bcrypt's format. This identifies a likely format only; it does not establish the password or verify a candidate.

## Test cases and expected results

Test with the inputs below in the UI. The value generated for the PHP case is intentionally generated locally and is not a credential.

| Case | Input/action | Expected result |
| --- | --- | --- |
| Supplied bcrypt form | Paste the example above | Likely bcrypt, `$2y$`, cost 10 / 2^10, 60-character modular crypt format, confidence note and limitation visible. |
| PHP-generated bcrypt | Run `php -r "echo password_hash('demo-only', PASSWORD_BCRYPT);"` and paste output | Likely bcrypt and its encoded cost are shown. Recognition does not verify `demo-only`. |
| MD5 digest | `5f4dcc3b5aa765d61d8327deb882cf99` | MD5-shaped, 32 hex characters; fast and inappropriate alone for password storage. |
| SHA-256 digest | `2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824` | SHA-256-shaped, 64 hex characters; shape-only identification and not a password KDF. |
| Malformed hash | `not-a-valid-hash` | Clear unrecognized/malformed message; result cards return to awaiting state. |
| Empty input | Clear input and select Analyze | “Enter a hash to analyze.” No exception or verification attempt. |
| SQL with hash | `SET @seed_password_hash = '$2y$10$sVtK1y05Jsk4hSsG5krjUOcDJW3c4loF13byj7Up.Z8fFoDdzxe1S'; INSERT INTO doctors (fullname, username, password) VALUES ('Example Doctor', 'doctor1', @seed_password_hash);` | Variable assignment appears, table `doctors` and columns appear, reuse is detected, and the likely bcrypt format is recognized without claiming it is verified. |
| SQL with plaintext | `SET @seed_password = 'demo-only-password'; INSERT INTO doctors (username, password) VALUES ('doctor1', @seed_password);` | Variable and reuse appear, with a warning that the quoted value may be plaintext and should not be seeded as-is. |

The supplied example is shown at page load to make the interface useful immediately; use **Reset** to test empty input. Try changing theme and viewport width for responsive layouts.

### Text-to-hash test cases

| Input / operation | Expected result |
| --- | --- |
| `Hello World` with SHA-256 | `a591a6d40bf420404a011733cfb7b190d62c65bf0bcda32b57b277d9ad9f146e`; repeating gives the same digest. |
| `MedConnect` with MD5 | 32-character educational digest. |
| Empty string with SHA-256 | Valid input; produces the standard empty-string digest `e3b0c442…b855`. |
| `Hello नमस्ते` with SHA-384 | Unicode is UTF-8 encoded; output is 96 hex characters. |
| Long multiline text with SHA-512 | Full multiline content is hashed; output is 128 hex characters. |
| Same password twice with bcrypt | Different `$2...` strings due to random salts; both verify with the correct candidate. Wrong candidate returns No Match. |
| PBKDF2-SHA-256 | Encoded value includes iteration count, random salt, and derived key; correct candidate matches and incorrect candidate does not. |
| Argon2id | Tested through local PHP when supported; option is disabled with an unavailable label otherwise. |
| Invalid PBKDF2 work factor / malformed verification hash | Clear inline error; correcting the field clears the error. |

To run the browser smoke test, use Node 20+ plus Chrome and PHP on the default XAMPP paths (or set `CHROME_PATH` / `PHP_HASH` for alternate installations):

```powershell
node tools/hash-explorer/smoke-test.cjs
```

The test drives the page through headless Chrome and local PHP. It checks the analyzer's eight cases plus SHA-256 repeatability, MD5, empty text, Unicode, long multiline input, PBKDF2, bcrypt random salts and verification, and Argon2id when PHP supports it.

## Files

- `index.html` — standalone accessible UI and content
- `styles.css` — isolated responsive theme and layout styles
- `app.js` — local recognition, SQL text inspection, copy, theme, and export actions
- `hash-lab.css` — isolated styles for the generator and verification panels
- `api.php` — optional same-origin PHP password hashing helper, loopback only; no database or persistence
- `preview.png` — captured desktop browser preview
- `preview.svg` — vector preview of the dashboard
- `smoke-test.cjs` — dependency-free browser test harness

No MedConnect authentication pages, session handling, seed scripts, credentials, or stored hashes are modified by this tool.
