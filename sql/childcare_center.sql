INSERT INTO child (child_id, name, age) 
VALUES
    (1, 'Maya Johnson', 4),
    (2, 'Liam Johnson', 3),
    (3, 'Sofia Rivera', 5),
    (4, 'Noah Williams', 4),
    (5, 'Ava Brown', 2);
INSERT INTO parent (parent_id, name, email, phone_number) 
VALUES
    (1, 'Denise Johnson', 'denise.johnson@example.com', '7185550101'),
    (2, 'Marcus Johnson', 'marcus.johnson@example.com', '7185550102'),
    (3, 'Elena Rivera',   'elena.rivera@example.com',   '7185550103'),
    (4, 'Tasha Brown',    'tasha.brown@example.com',    '7185550104');
INSERT INTO staff (staff_id, name, email, homeroom_id, phone_number) 
VALUES
    (1, 'Ms. Carter', 'carter@example.com', 101, '7185550201'),
    (2, 'Mr. Lopez',  'lopez@example.com',  102, '7185550202');
INSERT INTO parent_child (parent_id, child_id) 
VALUES
    (1, 1), (2, 1),
    (1, 2),
    (3, 3),
    (3, 4),
    (4, 5);
INSERT INTO attendance (attendance_id, date, child_id, record, time_in, time_out) VALUES
    (1, '2026-10-16', 1, 'Present', '08:05:00', '16:30:00'),
    (2, '2026-10-16', 2, 'Present', '08:10:00', NULL),    
    (3, '2026-10-16', 3, 'Absent',  NULL,       NULL),
    (4, '2026-10-17', 1, 'Present', '08:00:00', '17:00:00');
    
INSERT INTO invoices (invoice_id, parent_id, due_date, amount_requested) 
VALUES
    (1, 1, '2026-12-01', 450.00),
    (2, 3, '2026-12-01', 300.00);
INSERT INTO payments (payment_id, invoice_id, parent_id, amount_paid) 
VALUES
    (1, 1, 1, 200.00), 
    (2, 1, 1, 250.00);   

INSERT INTO enrollment (child_id, homeroom_id) 
VALUES (4, 101);
DELETE FROM enrollment WHERE child_id = 4;

SHOW TRIGGERS;
DROP TRIGGER enrollment_capacity_check;
DELIMITER $$

CREATE TRIGGER enrollment_capacity_check
BEFORE INSERT ON enrollment
FOR EACH ROW
BEGIN
    DECLARE current_count INT;
    DECLARE room_capacity INT;

    SELECT COUNT(*) INTO current_count
    FROM enrollment
    WHERE homeroom_id = NEW.homeroom_id;

    SELECT capacity INTO room_capacity
    FROM homeroom
    WHERE homeroom_id = NEW.homeroom_id;

    IF current_count >= room_capacity THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Homeroom is at capacity';
    END IF;
END$$

DELIMITER ;
INSERT INTO enrollment (child_id, homeroom_id) VALUES (4, 101);

DELETE FROM enrollment WHERE child_id = 4;

SELECT homeroom_id, COUNT(*) AS enrolled
FROM enrollment
GROUP BY homeroom_id;

INSERT INTO enrollment (enrollment_id, child_id, homeroom_id)
VALUES (1, 1, 101), (2, 2, 101), (3, 3, 102);

DELETE FROM attendance
WHERE child_id = 1 AND `date` = '2026-11-16' AND record = 'Absent';

SELECT * FROM attendance;

SHOW CREATE TABLE attendance;

ALTER TABLE attendance ADD UNIQUE (child_id, date);
DROP INDEX child_id_2 ON attendance;
SELECT * FROM attendance WHERE child_id = 1 AND date = '2026-11-16';
SELECT * FROM attendance;

-- TESTS --

INSERT INTO attendance (date, child_id, record) VALUES ('2026-10-16', 1, 'Absent');
INSERT INTO attendance (date, child_id, record, time_in, time_out)
VALUES ('2026-10-18', 1, 'Present', '17:00:00', '08:00:00');
INSERT INTO attendance (date, child_id, record) 
VALUES ('2026-10-18', 2, 'Late');
INSERT INTO child (name, age) VALUES ('Test Child', -1);

ALTER TABLE child MODIFY child_id INT AUTO_INCREMENT;
SET FOREIGN_KEY_CHECKS = 0;
ALTER TABLE child MODIFY child_id INT NOT NULL AUTO_INCREMENT;
SET FOREIGN_KEY_CHECKS = 1;
SELECT @@FOREIGN_KEY_CHECKS;

INSERT INTO child (name, age) VALUES ('Test Child', -1);

INSERT INTO parent (name, email, phone_number)
VALUES ('Copy Cat', 'denise.johnson@example.com', '7185550999');
INSERT INTO staff (name, email, homeroom_id, phone_number)
VALUES ('Test Staff', 'test@example.com', 999, '7185550998');

ALTER TABLE staff MODIFY staff_id INT NOT NULL AUTO_INCREMENT;
INSERT INTO staff (name, email, homeroom_id, phone_number)
VALUES ('Test Staff', 'test@example.com', 999, '7185550998');
INSERT INTO homeroom (homeroom_id, capacity) VALUES (103, 0);
INSERT INTO invoices (parent_id, due_date, amount_requested) 
VALUES (1, '2026-12-01', 0);
INSERT INTO payments (invoice_id, parent_id, amount_paid) 
VALUES (999, 1, 50.00);
DELETE FROM homeroom WHERE homeroom_id = 101;


INSERT INTO enrollment (child_id, homeroom_id) VALUES (4, 101);
INSERT INTO enrollment (child_id, homeroom_id) VALUES (1, 102);
INSERT INTO attendance (date, child_id, record) VALUES ('2026-11-16', 1, 'Absent');

SELECT h.homeroom_id, c.name AS child
FROM homeroom h
JOIN enrollment e ON e.homeroom_id = h.homeroom_id
JOIN child c ON c.child_id = e.child_id
ORDER BY h.homeroom_id, c.name;

SELECT c.name AS child, p.name AS parent
FROM child c
JOIN parent_child pc ON pc.child_id = c.child_id
JOIN parent p ON p.parent_id = pc.parent_id
ORDER BY c.name;

SELECT h.homeroom_id, h.capacity, COUNT(e.enrollment_id) AS enrolled
FROM homeroom h
LEFT JOIN enrollment e ON e.homeroom_id = h.homeroom_id
GROUP BY h.homeroom_id, h.capacity;

SELECT p.name, i.invoice_id, i.amount_requested,
       COALESCE(SUM(pay.amount_paid), 0) AS paid,
       i.amount_requested - COALESCE(SUM(pay.amount_paid), 0) AS balance
FROM invoices i
JOIN parent p ON p.parent_id = i.parent_id
LEFT JOIN payments pay ON pay.invoice_id = i.invoice_id
GROUP BY p.name, i.invoice_id, i.amount_requested;

ALTER TABLE staff
    ADD COLUMN password_hash VARCHAR(255) NULL,
    ADD COLUMN role VARCHAR(10) NOT NULL DEFAULT 'staff',
    ADD CONSTRAINT chk_staff_role CHECK (role IN ('staff', 'admin'));

ALTER TABLE parent
    ADD COLUMN password_hash VARCHAR(255) NULL;

UPDATE staff  SET password_hash = '$2y$10$zxaxd3N61Y6znlUtmJuQs.vLp4bLWxiPrQfw3J3DNCAgl4AQ.e4cu', role = 'admin' WHERE staff_id = 1;
UPDATE staff  SET password_hash = '$2y$10$zxaxd3N61Y6znlUtmJuQs.vLp4bLWxiPrQfw3J3DNCAgl4AQ.e4cu' WHERE staff_id = 2;
UPDATE parent SET password_hash = '$2y$10$zxaxd3N61Y6znlUtmJuQs.vLp4bLWxiPrQfw3J3DNCAgl4AQ.e4cu' WHERE parent_id = 1;
