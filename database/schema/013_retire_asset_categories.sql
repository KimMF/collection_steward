-- Collection Steward
-- Retire the legacy asset category structure after all assets have been
-- assigned a controlled asset_type_id.
--
-- IMPORTANT:
-- Before running this migration, verify that this query returns 0:
--
-- SELECT COUNT(*)
-- FROM assets
-- WHERE asset_type_id IS NULL;
--
-- Back up the database and run this file once. MySQL schema changes commit
-- automatically and should not be treated as a reversible transaction.

ALTER TABLE asset_types
    ADD COLUMN description TEXT NULL
        AFTER name,
    DROP FOREIGN KEY fk_asset_types_category,
    DROP INDEX idx_asset_types_category_id,
    DROP COLUMN category_id;


UPDATE asset_types
SET description = CASE name
    WHEN 'Skirt' THEN 'Garment hanging from the waist without separate leg sections.'
    WHEN 'Dress' THEN 'One-piece garment combining an upper body and skirt.'
    WHEN 'Blouse' THEN 'Upper-body garment with blouse construction or styling.'
    WHEN 'Shirt' THEN 'Upper-body garment with shirt construction or styling.'
    WHEN 'Pants' THEN 'Garment with one waist opening and two leg openings; shorts and culottes are classified as Pants rather than separate asset types.'
    WHEN 'Jacket' THEN 'Upper-body outer garment generally ending near the waist or hips.'
    WHEN 'Coat' THEN 'Outer garment generally longer or heavier than a jacket.'
    WHEN 'Vest' THEN 'Sleeveless upper-body garment worn over or under another garment.'
    WHEN 'Hat' THEN 'Headwear other than a wig or hairpiece.'
    WHEN 'Shoes' THEN 'Footwear classified and tracked as a pair or individual asset.'
    WHEN 'Accessory' THEN 'Wearable or carried item that is not another defined primary garment type.'
    WHEN 'Sweater' THEN 'Knitted or sweater-style upper-body garment.'
    ELSE description
END
WHERE name IN (
    'Skirt',
    'Dress',
    'Blouse',
    'Shirt',
    'Pants',
    'Jacket',
    'Coat',
    'Vest',
    'Hat',
    'Shoes',
    'Accessory',
    'Sweater'
);


ALTER TABLE assets
    DROP FOREIGN KEY fk_assets_category,
    DROP INDEX idx_assets_category_id,
    DROP COLUMN category_id;


DROP TABLE asset_categories;

