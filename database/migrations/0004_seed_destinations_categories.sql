-- Reference data for Phase 1. Fixed UUIDs so seeds are stable across environments.
INSERT INTO destinations (id, slug, name, description, hero_image_url) VALUES
  ('11111111-1111-4111-8111-111111111111', 'da-nang', 'Da Nang', 'Beaches, the Marble Mountains and the Han River city.', NULL),
  ('22222222-2222-4222-8222-222222222222', 'hoi-an', 'Hoi An', 'UNESCO-listed ancient town, lantern-lit streets and river life.', NULL)
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO categories (id, slug, name, icon) VALUES
  ('a1000000-0000-4000-8000-000000000001', 'food-and-drink', 'Food & Drink', 'utensils'),
  ('a1000000-0000-4000-8000-000000000002', 'culture-and-history', 'Culture & History', 'landmark'),
  ('a1000000-0000-4000-8000-000000000003', 'nature-and-outdoors', 'Nature & Outdoors', 'mountain'),
  ('a1000000-0000-4000-8000-000000000004', 'water-and-beach', 'Water & Beach', 'waves'),
  ('a1000000-0000-4000-8000-000000000005', 'workshops-and-crafts', 'Workshops & Crafts', 'palette'),
  ('a1000000-0000-4000-8000-000000000006', 'wellness', 'Wellness', 'leaf'),
  ('a1000000-0000-4000-8000-000000000007', 'day-trips', 'Day Trips', 'map')
ON DUPLICATE KEY UPDATE name = VALUES(name);
