# MedConnect – IoT-Based Health Monitoring System

## 📌 Overview
MedConnect is an IoT-based health monitoring system that uses an **ESP32** with sensors (MAX30102, Temperature Sensor, etc.) to track patient vitals such as **Heart Rate, SPO₂, Body Temperature, and Blood Pressure** in real-time.  
The data is sent to a **PHP + MySQL backend** hosted on **XAMPP**, where it can be viewed and managed through a web dashboard.

---

## 🚀 Features
- Real-time health metrics from IoT device
- Patient login and registration system
- Admin dashboard to manage patients and data
- MySQL database integration
- Responsive web interface using **HTML, CSS, JavaScript, PHP**
- Personal anomaly screening using a scikit-learn Isolation Forest model
- Local hosting with **XAMPP**

---

## 🛠 Requirements
- **ESP32** (with MAX30102 sensor + Temperature Sensor)
- **XAMPP** (Apache + MySQL)
- **Web Browser** (Chrome/Edge/Firefox)
- **Arduino IDE** (for ESP32 code upload)

---

## 📂 Installation & Setup

1. **Clone or Download the Repository**
   - Save the project folder in your **XAMPP** installation directory:
     ```
     C:/xampp/htdocs/medconnect
     ```

2. **Start XAMPP Server**
   - Open XAMPP Control Panel  
   - Start **Apache** and **MySQL** services

3. **Import the Database**
   - Go to: [http://localhost/phpmyadmin](http://localhost/phpmyadmin)  
   - Create a database (e.g., `medconnect_db`)  
   - Import the `.sql` file from the `database` folder of the project

4. **Access the Web Application**
   - Open any browser and go to:
     ```
     http://localhost/medconnect/
     ```

---

## 📜 Usage
- **For Admin**: Log in to manage patient accounts and view health records.
- **For Patients**: Log in to view personal health metrics and history.
- **For IoT Device**: ESP32 sends data to the PHP API automatically.

## ML anomaly screening

The patient dashboard screens the latest complete vital-sign reading against up to 100 of that patient's recent readings. It uses an unsupervised Isolation Forest model and reports pattern differences only; it is not a diagnosis or emergency alert system.

Install the Python dependency from the project directory:

```powershell
py -m pip install -r requirements.txt
```

The PHP bridge calls `ml/predict_health.py` with the patient's readings. If Python, scikit-learn, or enough complete readings are unavailable, the dashboard displays an explanatory status instead of blocking the rest of the application.

## Online deployment

The repository includes a `Dockerfile` for a PHP, MySQL-client, and Python deployment. Use a cloud host that supports Docker and connect it to a MySQL database. Import the schema from `read me .txt/sql.txt` before opening the application.

Set these environment variables in the cloud service. Do not commit their values:

```text
DB_HOST=your-mysql-host
DB_PORT=3306
DB_NAME=medconnect
DB_USER=your-mysql-user
DB_PASSWORD=your-mysql-password
PYTHON_BIN=python3
DEFAULT_PATIENT_ID=4
```

## Administrator doctor verification

Import the updated schema from `read me .txt/sql.txt`. For an existing database, run `read me .txt/migrate-verification.sql` once. New doctor registrations remain pending until an administrator approves them.

Configure the admin login with environment variables. Generate a password hash with `C:\xampp\php\php.exe -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"` and store the output as `ADMIN_PASSWORD_HASH`:

```text
ADMIN_USERNAME=your-admin-username
ADMIN_PASSWORD_HASH=your-generated-password-hash
```

Open `/admin-login.html` to review pending doctors and approve or reject them. Keep both admin values private.

After deployment, update `serverUrl` in both ESP32 instruction files to the public HTTPS URL ending in `/save_data.php`, then replace `YOUR_WIFI_PASSWORD` locally before uploading the sketch to the ESP32. Keep that real Wi-Fi password out of Git.

---

## 📁 Project Structure
