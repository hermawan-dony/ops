<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    exit('Unauthorized');
}

$type = $_POST['type'] ?? $_GET['type'] ?? '';
$action = $_POST['action'] ?? $_GET['action'] ?? 'add';

try {
    if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($type === 'driver') {
            $hashed_pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $nik = !empty($_POST['nik']) ? $_POST['nik'] : null;
            $wa_no = !empty($_POST['wa_no']) ? $_POST['wa_no'] : null;
            $supervisor_id = !empty($_POST['supervisor_id']) ? $_POST['supervisor_id'] : null;
            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, preferred_car_id, nik, wa_no, supervisor_id) VALUES (?, ?, ?, 'driver', ?, ?, ?, ?)");
            $stmt->execute([$_POST['username'], $hashed_pass, $_POST['full_name'], $_POST['preferred_car_id'], $nik, $wa_no, $supervisor_id]);
        } elseif ($type === 'car') {
            $stmt = $pdo->prepare("INSERT INTO master_cars (car_no, model, last_service_km) VALUES (?, ?, ?)");
            $stmt->execute([$_POST['car_no'], $_POST['model'], $_POST['last_service_km']]);
        } elseif ($type === 'passenger') {
            $stmt = $pdo->prepare("INSERT INTO master_passengers (name, wa_no) VALUES (?, ?)");
            $stmt->execute([$_POST['name'], $_POST['wa_no']]);
        } elseif ($type === 'destination') {
            $stmt = $pdo->prepare("INSERT INTO master_destinations (name) VALUES (?)");
            $stmt->execute([$_POST['name']]);
        } elseif ($type === 'holiday') {
            $stmt = $pdo->prepare("INSERT INTO master_holidays (holiday_date, description) VALUES (?, ?)");
            $stmt->execute([$_POST['holiday_date'], $_POST['description']]);
        }
    } elseif ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $_POST['id'];
        if ($type === 'driver') {
            $nik = !empty($_POST['nik']) ? $_POST['nik'] : null;
            $wa_no = !empty($_POST['wa_no']) ? $_POST['wa_no'] : null;
            $supervisor_id = !empty($_POST['supervisor_id']) ? $_POST['supervisor_id'] : null;
            if (!empty($_POST['password'])) {
                $hashed_pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username=?, password=?, full_name=?, preferred_car_id=?, nik=?, wa_no=?, supervisor_id=? WHERE id=?");
                $stmt->execute([$_POST['username'], $hashed_pass, $_POST['full_name'], $_POST['preferred_car_id'], $nik, $wa_no, $supervisor_id, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username=?, full_name=?, preferred_car_id=?, nik=?, wa_no=?, supervisor_id=? WHERE id=?");
                $stmt->execute([$_POST['username'], $_POST['full_name'], $_POST['preferred_car_id'], $nik, $wa_no, $supervisor_id, $id]);
            }
        } elseif ($type === 'car') {
            $stmt = $pdo->prepare("UPDATE master_cars SET car_no=?, model=?, last_service_km=? WHERE id=?");
            $stmt->execute([$_POST['car_no'], $_POST['model'], $_POST['last_service_km'], $id]);
        } elseif ($type === 'passenger') {
            $stmt = $pdo->prepare("UPDATE master_passengers SET name=?, wa_no=? WHERE id=?");
            $stmt->execute([$_POST['name'], $_POST['wa_no'], $id]);
        } elseif ($type === 'destination') {
            $stmt = $pdo->prepare("UPDATE master_destinations SET name=? WHERE id=?");
            $stmt->execute([$_POST['name'], $id]);
        } elseif ($type === 'holiday') {
            $stmt = $pdo->prepare("UPDATE master_holidays SET holiday_date=?, description=? WHERE id=?");
            $stmt->execute([$_POST['holiday_date'], $_POST['description'], $id]);
        }
    } elseif ($action === 'assign_car') {
        $driver_id = $_POST['driver_id'];
        $car_id = $_POST['car_id'] ?: null;
        $stmt = $pdo->prepare("UPDATE users SET preferred_car_id = ? WHERE id = ?");
        $stmt->execute([$car_id, $driver_id]);
    } elseif ($action === 'delete') {
        $id = $_GET['id'] ?? null;
        if ($id) {
            if ($type === 'driver') {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'driver'");
                $stmt->execute([$id]);
            } elseif ($type === 'car') {
                $stmt = $pdo->prepare("DELETE FROM master_cars WHERE id = ?");
                $stmt->execute([$id]);
            } elseif ($type === 'passenger') {
                $stmt = $pdo->prepare("DELETE FROM master_passengers WHERE id = ?");
                $stmt->execute([$id]);
            } elseif ($type === 'destination') {
                $stmt = $pdo->prepare("DELETE FROM master_destinations WHERE id = ?");
                $stmt->execute([$id]);
            } elseif ($type === 'holiday') {
                $stmt = $pdo->prepare("DELETE FROM master_holidays WHERE id = ?");
                $stmt->execute([$id]);
            }
        }
    } elseif ($action === 'bulk_delete') {
        $ids_str = $_POST['ids'] ?? $_GET['ids'] ?? '';
        if (!empty($ids_str)) {
            $ids = explode(',', $ids_str);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            if ($type === 'driver') {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id IN ($placeholders) AND role = 'driver'");
                $stmt->execute($ids);
            } elseif ($type === 'car') {
                $stmt = $pdo->prepare("DELETE FROM master_cars WHERE id IN ($placeholders)");
                $stmt->execute($ids);
            } elseif ($type === 'passenger') {
                $stmt = $pdo->prepare("DELETE FROM master_passengers WHERE id IN ($placeholders)");
                $stmt->execute($ids);
            } elseif ($type === 'destination') {
                $stmt = $pdo->prepare("DELETE FROM master_destinations WHERE id IN ($placeholders)");
                $stmt->execute($ids);
            } elseif ($type === 'holiday') {
                $stmt = $pdo->prepare("DELETE FROM master_holidays WHERE id IN ($placeholders)");
                $stmt->execute($ids);
            }
        }
    } elseif ($action === 'combine') {
        $ids_str = $_POST['ids'] ?? '';
        $target_name = trim($_POST['target_name'] ?? '');
        if (!empty($ids_str) && !empty($target_name)) {
            $ids = explode(',', $ids_str);
            if ($type === 'destination') {
                $stmt = $pdo->prepare("SELECT id FROM master_destinations WHERE LOWER(name) = LOWER(?)");
                $stmt->execute([$target_name]);
                $target = $stmt->fetch();
                
                if ($target) {
                    $target_id = $target['id'];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO master_destinations (name) VALUES (?)");
                    $stmt->execute([$target_name]);
                    $target_id = $pdo->lastInsertId();
                }
                
                $source_ids = array_values(array_filter($ids, function($val) use ($target_id) {
                    return $val != $target_id;
                }));
                
                if (!empty($source_ids)) {
                    $placeholders = implode(',', array_fill(0, count($source_ids), '?'));
                    $stmt = $pdo->prepare("UPDATE trips SET destination_id = ? WHERE destination_id IN ($placeholders)");
                    $stmt->execute(array_merge([$target_id], $source_ids));
                    
                    $stmt = $pdo->prepare("DELETE FROM master_destinations WHERE id IN ($placeholders)");
                    $stmt->execute($source_ids);
                }
                $_SESSION['success_message'] = "Berhasil menggabungkan tujuan menjadi '" . $target_name . "'.";
                
            } elseif ($type === 'passenger') {
                $stmt = $pdo->prepare("SELECT id FROM master_passengers WHERE LOWER(name) = LOWER(?)");
                $stmt->execute([$target_name]);
                $target = $stmt->fetch();
                
                if ($target) {
                    $target_id = $target['id'];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO master_passengers (name) VALUES (?)");
                    $stmt->execute([$target_name]);
                    $target_id = $pdo->lastInsertId();
                }
                
                $source_ids = array_values(array_filter($ids, function($val) use ($target_id) {
                    return $val != $target_id;
                }));
                
                if (!empty($source_ids)) {
                    $placeholders = implode(',', array_fill(0, count($source_ids), '?'));
                    $stmt = $pdo->prepare("UPDATE trips SET passenger_id = ? WHERE passenger_id IN ($placeholders)");
                    $stmt->execute(array_merge([$target_id], $source_ids));
                    
                    $stmt = $pdo->prepare("UPDATE users SET supervisor_id = ? WHERE supervisor_id IN ($placeholders)");
                    $stmt->execute(array_merge([$target_id], $source_ids));
                    
                    $stmt = $pdo->prepare("DELETE FROM master_passengers WHERE id IN ($placeholders)");
                    $stmt->execute($source_ids);
                }
                $_SESSION['success_message'] = "Berhasil menggabungkan penumpang menjadi '" . $target_name . "'.";
            }
        }
    }
} catch (Exception $e) {
    unset($_SESSION['success_message']);
    if (strpos($e->getMessage(), 'a foreign key constraint fails') !== false) {
        $_SESSION['error'] = 'Tidak dapat menghapus data ini karena sedang digunakan oleh data lain (misal: data trip atau shift).';
    } else {
        $_SESSION['error'] = 'Gagal memproses data: ' . $e->getMessage();
    }
}

header('Location: master_data.php');
exit;
?>
