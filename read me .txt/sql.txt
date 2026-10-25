create database if not exists medconnect;
use medconnect;
-- Doctors table
CREATE TABLE `doctors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fullname` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `department` varchar(50) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `verification_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Patients table
CREATE TABLE `patients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fullname` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `age` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `doctor_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `doctor_id` (`doctor_id`),
  CONSTRAINT `patients_ibfk_1` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Appointments table
CREATE TABLE `appointments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `appointment_date` date NOT NULL,
  `appointment_time` time NOT NULL,
  `status` enum('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  KEY `doctor_id` (`doctor_id`),
  CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Health metrics table
CREATE TABLE `health_metrics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `heart_rate` int(11) DEFAULT NULL,
  `spo2` int(11) DEFAULT NULL,
  `temperature` decimal(4,2) DEFAULT NULL,
  `blood_pressure_sys` int(11) DEFAULT NULL,
  `blood_pressure_dia` int(11) DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  CONSTRAINT `health_metrics_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `patient_suggestions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `doctor_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `suggestion` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `doctor_id` (`doctor_id`),
  KEY `patient_id` (`patient_id`),
  KEY `appointment_id` (`appointment_id`),
  CONSTRAINT `patient_suggestions_ibfk_1` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`),
  CONSTRAINT `patient_suggestions_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  CONSTRAINT `patient_suggestions_ibfk_3` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `patient_prescriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `doctor_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `prescription` text NOT NULL,
  `medication` varchar(100) DEFAULT NULL,
  `dosage` varchar(50) DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `doctor_id` (`doctor_id`),
  KEY `patient_id` (`patient_id`),
  KEY `appointment_id` (`appointment_id`),
  CONSTRAINT `patient_prescriptions_ibfk_1` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`),
  CONSTRAINT `patient_prescriptions_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  CONSTRAINT `patient_prescriptions_ibfk_3` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Password reset OTP storage. The application also creates this table automatically when needed.
CREATE TABLE `password_reset_otps` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role` varchar(20) NOT NULL,
  `account_id` int(11) NOT NULL,
  `otp_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `account_lookup` (`role`, `account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Administrator credentials are initialized from server-only ADMIN_USERNAME and
-- ADMIN_PASSWORD_HASH settings on first admin login. Existing credentials are preserved.
CREATE TABLE IF NOT EXISTS `admin_credentials` (
  `id` tinyint unsigned NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Local demonstration accounts only. These fixtures use a shared test password;
-- do not install or expose them as production accounts.
-- Re-running the script leaves existing fixture accounts untouched.
SET @seed_password_hash = '$2y$10$sVtK1y05Jsk4hSsG5krjUOcDJW3c4loF13byj7Up.Z8fFoDdzxe1S';

INSERT INTO `doctors` (`fullname`, `email`, `phone`, `department`, `username`, `password`, `verification_status`)
SELECT
  CONCAT('Dr. ', ELT(MOD(numbers.n - 1, 24) + 1,
    'Aarav Mehta', 'Ananya Sharma', 'Arjun Patel', 'Diya Nair',
    'Ethan Wilson', 'Fatima Khan', 'Grace Mitchell', 'Hannah Brooks',
    'Ibrahim Ali', 'Isabella Carter', 'James Anderson', 'Kavya Reddy',
    'Liam Thompson', 'Maya Johnson', 'Noah Williams', 'Olivia Martin',
    'Priya Kapoor', 'Rahul Verma', 'Sophia Davis', 'Thomas Evans',
    'Uma Iyer', 'Vikram Rao', 'William Taylor', 'Zoya Hussain')),
  CONCAT('doc', numbers.n, '@medconnect.local'),
  CONCAT('070', LPAD(numbers.n, 7, '0')),
  ELT(MOD(numbers.n - 1, 12) + 1,
    'Cardiology', 'Dermatology', 'General Medicine', 'Gynecology',
    'Neurology', 'Ophthalmology', 'Orthopedics', 'Pediatrics',
    'Psychiatry', 'Pulmonology', 'Dentistry', 'ENT'),
  CONCAT('doc', numbers.n),
  @seed_password_hash,
  'approved'
FROM (
  SELECT hundreds.n * 100 + tens.n * 10 + ones.n AS n
  FROM
    (SELECT 0 AS n UNION ALL SELECT 1) AS hundreds
    CROSS JOIN
    (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
     UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS tens
    CROSS JOIN
    (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
     UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS ones
  WHERE hundreds.n * 100 + tens.n * 10 + ones.n BETWEEN 1 AND 102
) AS numbers
ON DUPLICATE KEY UPDATE `id` = `id`;

INSERT INTO `patients` (`fullname`, `email`, `phone`, `age`, `username`, `password`)
SELECT
  ELT(MOD(numbers.n - 1, 24) + 1,
    'Aisha Khan', 'Ben Carter', 'Chloe Martin', 'Daniel Wilson',
    'Elena Garcia', 'Farhan Ahmed', 'Grace Lee', 'Harper Smith',
    'Ishaan Gupta', 'Julia Brown', 'Kabir Singh', 'Leah Davis',
    'Mateo Rodriguez', 'Nisha Patel', 'Oliver Clark', 'Pooja Menon',
    'Quinn Taylor', 'Riya Desai', 'Samuel Johnson', 'Tara Williams',
    'Usman Malik', 'Valentina Rossi', 'William Moore', 'Yasmin Ali'),
  CONCAT('pat', numbers.n, '@medconnect.local'),
  CONCAT('071', LPAD(numbers.n, 7, '0')),
  18 + MOD(numbers.n, 53),
  CONCAT('pat', numbers.n),
  @seed_password_hash
FROM (
  SELECT hundreds.n * 100 + tens.n * 10 + ones.n AS n
  FROM
    (SELECT 0 AS n UNION ALL SELECT 1) AS hundreds
    CROSS JOIN
    (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
     UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS tens
    CROSS JOIN
    (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
     UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS ones
  WHERE hundreds.n * 100 + tens.n * 10 + ones.n BETWEEN 1 AND 103
) AS numbers
ON DUPLICATE KEY UPDATE `id` = `id`;

-- Additional local demonstration accounts; no health readings are generated.
SET @seed_password_hash = '$2y$10$sVtK1y05Jsk4hSsG5krjUOcDJW3c4loF13byj7Up.Z8fFoDdzxe1S';

INSERT INTO `patients` (`fullname`, `email`, `phone`, `age`, `username`, `password`)
SELECT
  ELT(MOD(numbers.n - 104, 24) + 1,
    'Aisha Khan', 'Ben Carter', 'Chloe Martin', 'Daniel Wilson',
    'Elena Garcia', 'Farhan Ahmed', 'Grace Lee', 'Harper Smith',
    'Ishaan Gupta', 'Julia Brown', 'Kabir Singh', 'Leah Davis',
    'Mateo Rodriguez', 'Nisha Patel', 'Oliver Clark', 'Pooja Menon',
    'Quinn Taylor', 'Riya Desai', 'Samuel Johnson', 'Tara Williams',
    'Usman Malik', 'Valentina Rossi', 'William Moore', 'Yasmin Ali'),
  CONCAT('pat', numbers.n, '@medconnect.local'),
  CONCAT('071', LPAD(numbers.n, 7, '0')),
  18 + MOD(numbers.n, 53),
  CONCAT('pat', numbers.n),
  @seed_password_hash
FROM (
  SELECT thousands.n * 1000 + hundreds.n * 100 + tens.n * 10 + ones.n AS n
  FROM
    (SELECT 0 AS n UNION ALL SELECT 1) AS thousands
    CROSS JOIN
    (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
     UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS hundreds
    CROSS JOIN
    (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
     UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS tens
    CROSS JOIN
    (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
     UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS ones
  WHERE thousands.n * 1000 + hundreds.n * 100 + tens.n * 10 + ones.n BETWEEN 104 AND 1103
) AS numbers
ON DUPLICATE KEY UPDATE `id` = `id`;
