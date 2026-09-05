<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "medconnect";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$heart_rate = $_GET['heart_rate'];
$spo2 = $_GET['spo2'];
$temperature = $_GET['temperature'];
$patient_id = 4; // Replace with dynamic ID if needed

$sql = "INSERT INTO health_metrics (patient_id, heart_rate, spo2, temperature)
        VALUES ($patient_id, $heart_rate, $spo2, $temperature)";

if ($conn->query($sql) === TRUE) {
    echo "Data saved successfully.";
} else {
    echo "Error: " . $conn->error;
}
$conn->close();
?>
