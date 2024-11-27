-- Create table for zones
CREATE TABLE cp_zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    address VARCHAR(255) NOT NULL,
    radius_km FLOAT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create table for appointments
CREATE TABLE cp_appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zone_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    notes TEXT,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (zone_id) REFERENCES cp_zones(id)
);

-- Create table for users
CREATE TABLE cp_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create table for admin
CREATE TABLE cp_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES cp_users(id)
);

-- Create table for slots
CREATE TABLE cp_slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zone_id INT NOT NULL,
    day VARCHAR(50) NOT NULL,
    time TIME NOT NULL,
    FOREIGN KEY (zone_id) REFERENCES cp_zones(id)
);
