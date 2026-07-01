CREATE TABLE `master_cars` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `car_no` varchar(20) NOT NULL,
  `model` varchar(100) DEFAULT NULL,
  `last_service_km` int(11) DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `car_no` (`car_no`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=latin1;

CREATE TABLE `master_destinations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_dest_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=latin1;

CREATE TABLE `master_holidays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `holiday_date` date NOT NULL,
  `description` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `holiday_date` (`holiday_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `master_passengers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `wa_no` varchar(20) DEFAULT NULL,
  `pin` varchar(255) DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=latin1;

CREATE TABLE `outbox` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `wa_no` varchar(20) NOT NULL,
  `wa_text` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `shifts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `driver_id` int(11) NOT NULL,
  `shift_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time DEFAULT NULL,
  `status` enum('active','completed') DEFAULT 'active',
  `approval_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_by_name` varchar(100) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `overtime_early` decimal(5,2) DEFAULT '0.00',
  `overtime_late` decimal(5,2) DEFAULT '0.00',
  `is_edited` tinyint(4) DEFAULT '0',
  `supervisor_note` text,
  PRIMARY KEY (`id`),
  KEY `driver_id` (`driver_id`),
  CONSTRAINT `shifts_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=latin1;

CREATE TABLE `trip_expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `trip_id` int(11) NOT NULL,
  `expense_type` enum('gasoline','toll','parking','others','lunch') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `litre` decimal(10,2) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `supervisor_note` text,
  `approval_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by_name` varchar(100) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `trip_id` (`trip_id`),
  CONSTRAINT `trip_expenses_ibfk_1` FOREIGN KEY (`trip_id`) REFERENCES `trips` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=latin1;

CREATE TABLE `trips` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `shift_id` int(11) NOT NULL,
  `destination_id` int(11) DEFAULT NULL,
  `passenger_id` int(11) DEFAULT NULL,
  `car_id` int(11) NOT NULL,
  `km_start` int(11) NOT NULL,
  `km_start_photo` varchar(255) DEFAULT NULL,
  `km_end` int(11) DEFAULT NULL,
  `km_end_photo` varchar(255) DEFAULT NULL,
  `start_lat` decimal(10,8) DEFAULT NULL,
  `start_lng` decimal(11,8) DEFAULT NULL,
  `end_lat` decimal(10,8) DEFAULT NULL,
  `end_lng` decimal(11,8) DEFAULT NULL,
  `passenger_approval` enum('pending','approved','rejected') DEFAULT 'pending',
  `approval_token` varchar(100) DEFAULT NULL,
  `passenger_feedback` text,
  `start_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `end_time` timestamp NULL DEFAULT NULL,
  `status` enum('ongoing','completed') DEFAULT 'ongoing',
  `qr_expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shift_id` (`shift_id`),
  KEY `destination_id` (`destination_id`),
  KEY `passenger_id` (`passenger_id`),
  KEY `car_id` (`car_id`),
  CONSTRAINT `trips_ibfk_1` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `trips_ibfk_2` FOREIGN KEY (`destination_id`) REFERENCES `master_destinations` (`id`),
  CONSTRAINT `trips_ibfk_3` FOREIGN KEY (`passenger_id`) REFERENCES `master_passengers` (`id`),
  CONSTRAINT `trips_ibfk_4` FOREIGN KEY (`car_id`) REFERENCES `master_cars` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=latin1;

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `role` enum('admin','driver') DEFAULT 'driver',
  `preferred_car_id` int(11) DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `nik` varchar(20) DEFAULT NULL,
  `wa_no` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=latin1;

