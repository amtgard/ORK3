-- Schema-only excerpt from 2022-11-16-add-gmr-to-officers (officer rows are generated).

ALTER TABLE
    `ork_officer`
MODIFY COLUMN
    `role` enum(
        'Monarch','Regent','Prime Minister','Champion','GMR'
    )
NOT NULL AFTER `mundane_id`;
