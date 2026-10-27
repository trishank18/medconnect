MedConnect local setup with XAMPP

1. Place the project folder at C:\xampp\htdocs\medconnect.
2. Start Apache and MySQL from the XAMPP Control Panel.
3. For a new local database only, create `medconnect` in phpMyAdmin and import `database/setup.sql`.
   For an existing database, do not import the full setup script; use only a required migration.
4. Configure the server-only values in the project `.env` file. Never use the template values as secrets.
5. Open http://localhost/medconnect/ and sign in with an existing account.

If port 3306 is already in use, identify the owning MySQL service first and stop it
gracefully through the service's own controls if appropriate. Do not force-kill an
unknown process by PID; doing so can interrupt another database or lose unsaved work.

Before sending sensor data, configure `DEVICE_INGEST_TOKEN` and the correct
`DEVICE_PATIENT_ID` on the server, then place the matching token on the device.
Use HTTPS whenever sensor traffic leaves a trusted local development network.
