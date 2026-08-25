-- Collection Steward
-- Seed the initial approved intake vocabulary.
--
-- Run database/schema/011_add_controlled_asset_attributes.sql first.
-- This file is safe to run again: INSERT IGNORE skips a value when a record
-- with the same unique name already exists.
--
-- Asset type category_id values are deliberately left NULL. They can be linked
-- to existing broad categories later without affecting intake.

INSERT IGNORE INTO asset_types (name, display_order)
VALUES
    ('Skirt', 10),
    ('Dress', 20),
    ('Blouse', 30),
    ('Shirt', 40),
    ('Pants', 50),
    ('Jacket', 60),
    ('Coat', 70),
    ('Vest', 80),
    ('Hat', 90),
    ('Shoes', 100),
    ('Accessory', 110);


INSERT IGNORE INTO wearer_options (name, display_order)
VALUES
    ('Women''s', 10),
    ('Men''s', 20),
    ('Unisex/Any', 30),
    ('Child', 40),
    ('Unknown', 50);


INSERT IGNORE INTO color_options (name, display_order)
VALUES
    ('Black', 10),
    ('White', 20),
    ('Gray', 30),
    ('Brown', 40),
    ('Tan', 50),
    ('Beige', 60),
    ('Cream/Ivory', 70),
    ('Red', 80),
    ('Burgundy', 90),
    ('Orange', 100),
    ('Yellow', 110),
    ('Green', 120),
    ('Teal', 130),
    ('Blue', 140),
    ('Navy', 150),
    ('Purple', 160),
    ('Pink', 170),
    ('Gold', 180),
    ('Silver', 190),
    ('Multicolor', 200),
    ('Unknown', 210);


INSERT IGNORE INTO size_options (name, display_order)
VALUES
    ('Extra Small', 10),
    ('Small', 20),
    ('Medium', 30),
    ('Large', 40),
    ('Extra Large', 50),
    ('One Size', 60),
    ('Unknown', 70);


INSERT IGNORE INTO length_options (name, display_order)
VALUES
    ('Short', 10),
    ('Medium', 20),
    ('Long', 30),
    ('Not applicable', 40),
    ('Unknown', 50);


-- These are reusable descriptive or condition attributes. The two condition
-- tags may already exist from migration 004; INSERT IGNORE leaves them intact.
INSERT IGNORE INTO tags (name)
VALUES
    ('Sleeveless'),
    ('Short sleeves'),
    ('Long sleeves'),
    ('Elastic waist'),
    ('Adjustable waist'),
    ('Pockets'),
    ('Zipper closure'),
    ('Button closure'),
    ('Hook-and-eye closure'),
    ('Pull-on'),
    ('Lace'),
    ('Beaded'),
    ('Sequined'),
    ('Embroidered'),
    ('Patterned'),
    ('Solid'),
    ('Sheer'),
    ('Stretch fabric'),
    ('Matching set'),
    ('Period style'),
    ('Uniform'),
    ('Maternity'),
    ('Petite'),
    ('Plus size'),
    ('Tall'),
    ('Needs laundering'),
    ('Needs repair');
