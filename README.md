# Chat System API

A complete real-time chat system built with PHP, designed for modern messaging applications with support for direct and group conversations, file attachments, reactions, mentions, and real-time features.

## Features

✅ **Core Chat Functionality**

- User registration and authentication with token-based security
- Direct (1-on-1) and group conversations
- Real-time message sending and receiving
- File attachments with multiple format support
- Message reactions with emoji support
- User mentions and notifications
- Message search across conversations
- Read receipts and message status tracking
- Typing indicators
- Direct conversation lookup by username
- Dedicated group creation endpoint

✅ **User Management**

- Secure user profiles with avatar support
- User search and discovery
- Multi-device support
- Device registration for push notifications

✅ **Security & Performance**

- JWT-based authentication
- Input validation and sanitization
- Secure file upload handling
- Optimized database queries with pagination
- CORS support for web applications

## Database Schema

The system uses the following core tables:

- `users` - User accounts and profiles
- `auth_tokens` - Authentication tokens for secure access
- `conversations` - Chat conversations (direct and group)
- `conversation_participants` - User participation in conversations
- `messages` - Chat messages with reply support
- `message_attachments` - File attachments
- `message_events` - Message delivery and read status
- `message_mentions` - User mentions in messages
- `message_reactions` - Emoji reactions to messages
- `user_devices` - Device registration for push notifications

## Quick Start

### Prerequisites

- PHP 8.0 or higher
- MySQL 8.0 or higher
- Redis 6+ for caching and real-time features
- Composer (for dependencies)

### Installation

1. **Clone the repository**

   ```bash
   git clone https://github.com/md-riaz/chat.mdriaz.com.bd.git
   cd chat.mdriaz.com.bd
   ```

2. **Set up the database**

   ```bash
   mysql -u your_username -p < chat.sql
   mysql -u your_username -p chat < chat_seed.sql
   ```

3. **Configure the application**

   - Copy `.env.example` to `.env.local` and update values to match your environment (database, Redis, WebSocket, etc.)
     ```bash
     cp .env.example .env.local
     ```
   - Alternatively update settings directly in `configuration/config.php`
   - Set up your domain and security settings

4. **Start supporting services**
   - Make sure your Redis server is running
   - Start the WebSocket server for real-time features
     ```bash
     php bin/chat-server.php
     ```

### Demo Credentials

The seed file creates two demo users:

| Name       | Email             | Username | Password    |
| ---------- | ----------------- | -------- | ----------- |
| Alice Demo | alice@example.com | alice    | password123 |
| Bob Demo   | bob@example.com   | bob      | password123 |

### Environment Variables

Configuration values are loaded from `.env.local`. Important options include:

- `APP_URL` – Base URL of the application
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_PORT` – Database settings
- `REDIS_HOST`, `REDIS_PORT` – Redis connection for caching and presence
- `WEBSOCKET_HOST`, `WEBSOCKET_PORT` – WebSocket server configuration
- `ALLOWED_ORIGINS` – Allowed CORS origins
- `PAGINATION_LIMIT` – Default items per page

### Basic Usage

#### User Registration

```bash
curl -X POST http://your-domain.com/api/user/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Doe",
    "email": "john@example.com",
    "username": "johndoe",
    "password": "securepassword123"
  }'
```

#### User Login

```bash
curl -X POST http://your-domain.com/api/user/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john@example.com",
    "password": "securepassword123"
  }'
```

#### Send a Message

```bash
curl -X POST http://your-domain.com/api/message \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "conversation_id": 1,
    "content": "Hello, world!"
  }'
```

## API Documentation

### Authentication Endpoints

| Method | Endpoint             | Description                  |
| ------ | -------------------- | ---------------------------- |
| POST   | `/api/user/register` | Register new user            |
| POST   | `/api/user/login`    | User login                   |
| POST   | `/api/user/logout`   | User logout                  |
| POST   | `/api/user/refresh`  | Refresh authentication token |
| GET    | `/api/user/me`       | Get current user info        |

### User Management

| Method | Endpoint                      | Description            |
| ------ | ----------------------------- | ---------------------- |
| GET    | `/api/user`                   | List users (paginated) |
| GET    | `/api/user/{id}`              | Get user by ID         |
| PUT    | `/api/user/{id}`              | Update user profile    |
| DELETE | `/api/user/{id}`              | Delete user account    |
| GET    | `/api/user/search`            | Search users           |
| POST   | `/api/user/{id}/uploadAvatar` | Upload user avatar     |

### Conversations

| Method | Endpoint                                    | Description               |
| ------ | ------------------------------------------- | ------------------------- |
| GET    | `/api/conversation`                         | List user's conversations |
| POST   | `/api/conversation`                         | Create new conversation   |
| GET    | `/api/conversation/{id}`                    | Get conversation details  |
| PUT    | `/api/conversation/{id}`                    | Update conversation       |
| DELETE | `/api/conversation/{id}`                    | Delete conversation       |
| GET    | `/api/conversation/{id}/participants`       | Get participants          |
| POST   | `/api/conversation/{id}/add-participants`   | Add participants          |
| POST   | `/api/conversation/{id}/remove-participant` | Remove participant        |
| POST   | `/api/conversation/{id}/mark-read`          | Mark as read              |

### Messages

| Method | Endpoint                      | Description               |
| ------ | ----------------------------- | ------------------------- |
| GET    | `/api/message`                | Get conversation messages |
| POST   | `/api/message`                | Send new message          |
| GET    | `/api/message/{id}`           | Get specific message      |
| PUT    | `/api/message/{id}`           | Edit message              |
| DELETE | `/api/message/{id}`           | Delete message            |
| POST   | `/api/message/{id}/reaction`  | Add/remove reaction       |
| POST   | `/api/message/{id}/mark-read` | Mark message as read      |
| POST   | `/api/message/upload`         | Upload file attachment    |
| GET    | `/api/message/search`         | Search messages           |

### Devices & Push Notifications

| Method | Endpoint                      | Description                       |
| ------ | ----------------------------- | --------------------------------- |
| POST   | `/api/device/register`        | Register device for notifications |
| GET    | `/api/device`                 | Get user's devices                |
| PUT    | `/api/device/{deviceId}`      | Update device info                |
| DELETE | `/api/device/{deviceId}`      | Unregister device                 |
| POST   | `/api/device/{deviceId}/ping` | Update device activity            |

### System Status

| Method | Endpoint                | Description                   |
| ------ | ----------------------- | ----------------------------- |
| GET    | `/api/status`           | API health check              |
| GET    | `/api/status/auth`      | Check authentication status   |
| GET    | `/api/status/database`  | Check database connection     |
| GET    | `/api/status/websocket` | Check WebSocket server status |

## Real-time Features

The system includes a WebSocket server for real-time functionality:

```javascript
// Connect to WebSocket
const ws = new WebSocket("ws://your-domain.com:8080/chat");

// Authenticate
ws.onopen = () => {
  ws.send(
    JSON.stringify({
      type: "auth",
      token: "your_auth_token",
    })
  );
};

// Handle incoming messages
ws.onmessage = (event) => {
  const data = JSON.parse(event.data);
  switch (data.type) {
    case "new_message":
      // Handle new message
      break;
    case "typing_status":
      // Handle typing indicator
      break;
    case "message_reaction":
      // Handle reaction update
      break;
  }
};

// Send a message
ws.send(
  JSON.stringify({
    type: "send_message",
    conversation_id: 1,
    content: "Hello from WebSocket!",
  })
);
```

## Response Format

All API responses follow this format:

```json
{
  "success": true|false,
  "message": "Description of the result",
  "data": {
    // Response data (optional)
  }
}
```

### Paginated Responses

```json
{
  "success": true,
  "message": "Items retrieved successfully",
  "data": {
    "items": [...],
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total": 100,
      "total_pages": 5,
      "has_next": true,
      "has_prev": false
    }
  }
}
```

## Architecture

```
├── application/Api/           # API Controllers and Models
│   ├── ApiController.php     # Base API controller
│   ├── Chat.php              # Chat convenience endpoints
│   ├── Conversation.php      # Conversation management
│   ├── Device.php            # Device registration
│   ├── Message.php           # Message handling
│   ├── Status.php            # System status
│   ├── User.php              # User management
│   ├── Enum/
│   │   └── Status.php        # Status constants
│   ├── Models/               # Data models
│   │   ├── AuthTokenModel.php
│   │   ├── ConversationModel.php
│   │   ├── ConversationParticipantModel.php
│   │   ├── DeviceModel.php
│   │   ├── MessageAttachmentModel.php
│   │   ├── MessageModel.php
│   │   └── UserModel.php
│   └── Services/
│       ├── ChatService.php   # Business logic service
│       └── RedisService.php  # Redis helper utilities
├── application/Jobs/         # Background jobs
│   └── SendPushNotification.php
├── bin/                      # Command-line scripts
│   └── chat-server.php      # WebSocket server
├── framework/                # Core framework
└── configuration/            # Configuration files
```

## Security Considerations

- All passwords are hashed using PHP's `password_hash()`
- JWT tokens have configurable expiration times
- File uploads are validated for type and size
- SQL injection protection through prepared statements
- Input validation and sanitization on all endpoints
- CORS support for cross-origin requests

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Support

For support and questions:

- Create an issue on GitHub
- Email: support@mdriaz.com.bd

## Roadmap

- [ ] End-to-end encryption
- [ ] Voice message support
- [ ] Video calling integration
- [ ] Message scheduling
- [ ] Advanced moderation tools
- [ ] Analytics dashboard
- [ ] Multi-language support
