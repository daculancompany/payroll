-- Statutory contribution rates, versioned by effectivity date.
--
-- The SSS bracket table and the PhilHealth formula were hardcoded PHP arrays
-- inside admin_class.php, labelled "2025". Editing them to adopt a new year's
-- rates silently restated every payroll ever computed, because a recalculated
-- 2025 run would be priced with 2026 numbers. Rates live here instead, and the
-- resolver picks the row in force on the payroll's own date_from.
--
-- config is JSON, shaped per kind:
--   sss  { "msc_step":500, "ee_rate":0.05, "msc_min":5000, "msc_max":35000 }
--   phic { "rate":0.05, "floor":12000, "ceiling":50000, "ee_share":0.5 }
--   hdmf { "low_rate":0.01, "high_rate":0.02, "threshold":1500, "cap_base":5000 }
CREATE TABLE IF NOT EXISTS statutory_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(10) NOT NULL COMMENT 'sss | phic | hdmf',
    effective_from DATE NOT NULL,
    config TEXT NOT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    date_created DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_kind_from (kind, effective_from)
);

-- SSS: 15% total of the Monthly Salary Credit, 5% employee share (RA 11199,
-- 2025 schedule). MSC runs 5,000–35,000 in 500 steps, so the EE share is the
-- MSC floored to its step, times 5% — which reproduces the hardcoded bracket
-- table exactly (5,000 -> 250 ... 35,000+ -> 1,750).
INSERT IGNORE INTO statutory_rates (kind, effective_from, config, notes) VALUES
('sss', '2025-01-01',
 '{"msc_step":500,"ee_rate":0.05,"msc_min":5000,"msc_max":35000}',
 'RA 11199 2025 schedule — 15% total, 5% EE, MSC 5k-35k'),

-- PhilHealth: 5% of monthly basic, split equally, floor 10,000 / ceiling
-- 100,000 by law — but the figures previously hardcoded here used a 12,000
-- floor and a 50,000 ceiling, so those are kept as the seeded values to avoid
-- silently changing what this installation already deducts. Adjust in
-- Pay Settings when the client confirms which schedule they follow.
('phic', '2025-01-01',
 '{"rate":0.05,"floor":12000,"ceiling":50000,"ee_share":0.5}',
 'Matches the previously hardcoded calculatePhilHealth() figures'),

-- Pag-IBIG (HDMF): 1% employee share up to 1,500 monthly compensation, 2%
-- above it, computed on a base capped at 5,000 — so the employee share tops
-- out at 100. There was no Pag-IBIG calculator at all before this.
('hdmf', '2025-01-01',
 '{"low_rate":0.01,"high_rate":0.02,"threshold":1500,"cap_base":5000}',
 'HDMF Circular 460 — 1%/2% EE share, base capped at 5,000');
