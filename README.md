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

---

## 📁 Project Structure
