USE medconnect;

ALTER TABLE doctors
  ADD COLUMN verification_status enum('pending','approved','rejected') NOT NULL DEFAULT 'pending'
  AFTER password;
