<?php
/**
 * การตั้งค่าระบบคิวโรงพยาบาล
 * แก้ไขค่าในไฟล์นี้ให้ตรงกับเครื่องเซิร์ฟเวอร์ของโรงพยาบาล
 */
return [
    // ---------- ฐานข้อมูลของระบบคิว ----------
    'db' => [
        'dsn'  => 'mysql:host=127.0.0.1;port=3306;dbname=hp_queue;charset=utf8mb4',
        'user' => 'root',
        'pass' => '',
    ],

    // ---------- ฐานข้อมูล HN ของโรงพยาบาล (HIS) ----------
    // ค่าเริ่มต้นชี้ไปที่ตารางจำลอง his_patient / his_visit ในฐานข้อมูลเดียวกัน
    // เมื่อเชื่อมระบบจริง ให้เปลี่ยน dsn/user/pass และแก้ SQL ด้านล่างให้ตรงกับโครงสร้าง HIS
    // SQL ต้องคืนชื่อคอลัมน์ (alias) ตามที่กำหนดในวงเล็บ
    'his' => [
        'dsn'  => null,          // null = ใช้การเชื่อมต่อเดียวกับระบบคิว
        'user' => null,
        'pass' => null,

        // ต้องคืน: hn, prename, fname, lname, birthdate (Y-m-d), phone, cid
        'sql_patient' => 'SELECT hn, prename, fname, lname, birthdate, phone, cid
                           FROM his_patient WHERE hn = :hn LIMIT 1',

        // ต้องคืน: visit_date, department, doctor, diagnosis, treatment, medication
        'sql_history' => 'SELECT visit_date, department, doctor, diagnosis, treatment, medication
                           FROM his_visit WHERE hn = :hn ORDER BY visit_date DESC LIMIT 100',

        /* ตัวอย่างสำหรับ HOSxP (ปรับตามเวอร์ชันที่ใช้งาน)
        'sql_patient' => "SELECT p.hn, p.pname AS prename, p.fname, p.lname, p.birthday AS birthdate,
                                 p.hometel AS phone, p.cid
                          FROM patient p WHERE p.hn = :hn LIMIT 1",
        'sql_history' => "SELECT o.vstdate AS visit_date, k.department, d.name AS doctor,
                                 i.name AS diagnosis, '' AS treatment, '' AS medication
                          FROM ovst o
                          LEFT JOIN kskdepartment k ON k.depcode = o.main_dep
                          LEFT JOIN doctor d ON d.code = o.doctor
                          LEFT JOIN ovstdiag od ON od.vn = o.vn AND od.diagtype = '1'
                          LEFT JOIN icd101 i ON i.code = od.icd10
                          WHERE o.hn = :hn ORDER BY o.vstdate DESC LIMIT 100",
        */

        // จำนวนหลักของ HN (เติม 0 ด้านหน้าอัตโนมัติ) — ตั้ง 0 ถ้าไม่ต้องการเติม
        'hn_pad' => 9,
    ],

    'timezone' => 'Asia/Bangkok',
];
