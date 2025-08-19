-- Minimal seed data for chat system

-- Users
INSERT INTO users (id, name, email, username, password, created_at)
VALUES
  (1, 'Alice Demo', 'alice@example.com', 'alice', '$2y$12$BSe2oxWULwSOUQwGGW4iAOoDB//P4IbFrPoCkZuxtjmSOe8mcTJ/G', NOW()),
  (2, 'Bob Demo', 'bob@example.com', 'bob', '$2y$12$BSe2oxWULwSOUQwGGW4iAOoDB//P4IbFrPoCkZuxtjmSOe8mcTJ/G', NOW());

-- Conversation between Alice and Bob
INSERT INTO conversations (id, title, is_group, created_by, created_at)
VALUES (1, 'Demo Conversation', 0, 1, NOW());

-- Participants
INSERT INTO conversation_participants (id, conversation_id, user_id, role, joined_at)
VALUES
  (1, 1, 1, 'admin', NOW()),
  (2, 1, 2, 'member', NOW());

-- Example messages
INSERT INTO messages (id, conversation_id, sender_id, content, message_type, created_at)
VALUES
  (1, 1, 1, 'Hello Bob!', 'text', NOW()),
  (2, 1, 2, 'Hi Alice!', 'text', NOW());

-- Reset auto-increment counters
ALTER TABLE users AUTO_INCREMENT = 3;
ALTER TABLE conversations AUTO_INCREMENT = 2;
ALTER TABLE conversation_participants AUTO_INCREMENT = 3;
ALTER TABLE messages AUTO_INCREMENT = 3;
