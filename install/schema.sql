-- ระบบคิวโรงพยาบาล : โครงสร้างฐานข้อมูล
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  skey   VARCHAR(50) PRIMARY KEY,
  svalue TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS departments (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(20)  NOT NULL UNIQUE,
  name            VARCHAR(100) NOT NULL,
  prefix          VARCHAR(3)   NOT NULL,
  rooms           TINYINT      NOT NULL DEFAULT 1,
  avg_service_min SMALLINT     NOT NULL DEFAULT 10,
  daily_limit     SMALLINT     NOT NULL DEFAULT 0,
  allow_online    TINYINT(1)   NOT NULL DEFAULT 1,
  active          TINYINT(1)   NOT NULL DEFAULT 1,
  sort            SMALLINT     NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(100) NOT NULL,
  role          ENUM('admin','staff','doctor') NOT NULL,
  department_id INT NULL,
  room          VARCHAR(30) NULL,
  active        TINYINT(1) NOT NULL DEFAULT 1,
  last_login    DATETIME NULL,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS queue_counters (
  queue_date    DATE NOT NULL,
  department_id INT  NOT NULL,
  last_no       INT  NOT NULL,
  PRIMARY KEY (queue_date, department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS queues (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  queue_date    DATE NOT NULL,
  department_id INT  NOT NULL,
  queue_no      INT  NOT NULL,
  queue_code    VARCHAR(10) NOT NULL,
  hn            VARCHAR(20) NOT NULL,
  patient_name  VARCHAR(150) NOT NULL,
  source        ENUM('kiosk','counter','online') NOT NULL,
  status        ENUM('registered','waiting','serving','pharmacy','done','skipped','cancelled') NOT NULL,
  room          VARCHAR(30) NULL,
  created_at    DATETIME NOT NULL,
  checkin_at    DATETIME NULL,
  called_at     DATETIME NULL,
  announced_at  DATETIME NULL,
  pharmacy_at   DATETIME NULL,
  finished_at   DATETIME NULL,
  recall_count  TINYINT NOT NULL DEFAULT 0,
  created_by    INT NULL,
  called_by     INT NULL,
  UNIQUE KEY uq_no (queue_date, department_id, queue_no),
  KEY ix_dept_status (queue_date, department_id, status),
  KEY ix_hn (hn, queue_date),
  FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ======== ตารางจำลองฐานข้อมูล HN ของโรงพยาบาล (ใช้ทดสอบ) ========
-- เมื่อเชื่อม HIS จริง ให้ตั้งค่าใน config.php ส่วน 'his' แทน
CREATE TABLE IF NOT EXISTS his_patient (
  hn        VARCHAR(20) PRIMARY KEY,
  cid       VARCHAR(13),
  prename   VARCHAR(20),
  fname     VARCHAR(60),
  lname     VARCHAR(60),
  birthdate DATE,
  phone     VARCHAR(20)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS his_visit (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  hn         VARCHAR(20) NOT NULL,
  visit_date DATE NOT NULL,
  department VARCHAR(100),
  doctor     VARCHAR(100),
  diagnosis  VARCHAR(255),
  treatment  VARCHAR(255),
  medication VARCHAR(255),
  KEY (hn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
