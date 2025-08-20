# Chat System REST API Documentation

## Base URL

```
https://yourdomain.com/api/
```

## Setup Requirements

- Configure environment variables in `.env.local` (database, Redis, WebSocket)
- Ensure MySQL and Redis servers are running
- Start the WebSocket server with `php bin/chat-server.php` for real-time features
- Optional: import `chat_seed.sql` for demo data (`alice@example.com` / `password123` and `bob@example.com` / `password123`)

## Authentication

All API endpoints (except login and register) require Bearer token authentication.

### Headers

```
Authorization: Bearer {your_access_token}
Content-Type: application/json
```

---

## Authentication Endpoints

### POST /api/user/login

Login user and get access token.

**Request Body:**

```json
{
  "email": "user@example.com",
  "password": "password123",
  "device_id": "device_123",
  "platform": "android",
  "fcm_token": "fcm_token_here"
}
```

**Response:**

```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "token": "access_token_here",
    "user": {
      "id": 1,
      "name": "John Doe",
      "email": "user@example.com",
      "username": "johndoe",
      "avatar_url": "/uploads/avatars/avatar.jpg"
    }
  }
}
```

### POST /api/user/register

Register a new user.

**Request Body:**

```json
{
  "name": "John Doe",
  "email": "user@example.com",
  "username": "johndoe",
  "password": "password123",
  "avatar_url": "/uploads/avatars/avatar.jpg"
}
```

### POST /api/user/logout

Logout and revoke access token.

### POST /api/user/refresh

Refresh access token.

### GET /api/user/me

Get current authenticated user information.

---

## User Management Endpoints

### GET /api/user

Get list of users with pagination and search.

**Query Parameters:**

- `page` (int): Page number (default: 1)
- `per_page` (int): Items per page (default: 20, max: 100)
- `search` (string): Search query for name, email, or username

### GET /api/user/{id}

Get specific user by ID.

### PUT /api/user/{id}

Update user profile.

**Request Body:**

```json
{
  "name": "Updated Name",
  "username": "newusername",
  "avatar_url": "/uploads/avatars/new_avatar.jpg"
}
```

### DELETE /api/user/{id}

Soft delete user account.

### POST /api/user/{id}/uploadAvatar

Upload user avatar image.

**Form Data:**

- `avatar`: Image file (JPEG, PNG, GIF, WebP, max 5MB)

### GET /api/user/search

Search users for adding to conversations.

**Query Parameters:**

- `q` (string): Search query
- `limit` (int): Max results (default: 10, max: 50)

### GET /api/user/me

Get current user profile.

---

## Conversation Management Endpoints

### GET /api/conversation

Get user's conversations with pagination.

**Query Parameters:**

- `page` (int): Page number
- `per_page` (int): Items per page

**Response:**

```json
{
  "success": true,
  "message": "Conversations retrieved successfully",
  "data": {
    "items": [
      {
        "id": 1,
        "title": "Project Team",
        "is_group": 1,
        "participant_count": 3,
        "last_message": "See you tomorrow",
        "last_message_time": "2024-05-01T12:34:56Z",
        "last_sender_name": "Alice",
        "unread_count": 2
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total": 1,
      "total_pages": 1,
      "has_next": false,
      "has_prev": false
    }
  }
}
```

### POST /api/conversation

Create a new conversation.

**Request Body (Direct Conversation):**

```json
{
  "type": "direct",
  "participant_id": 2
}
```

**Request Body (Group Conversation):**

```json
{
  "type": "group",
  "name": "Project Team",
"description": "Discussion for the new project",
"participant_ids": [2, 3, 4]
}
```

**Response:**

```json
{
  "success": true,
  "message": "Conversation created successfully",
  "data": {
    "id": 10,
    "title": "Project Team",
    "is_group": 1,
    "user_role": "admin",
    "participant_count": 4,
    "last_read_message_id": null,
    "created_by": 1
  }
}
```

### GET /api/conversation/{id}

Get conversation details.

**Response:**

```json
{
  "success": true,
  "message": "Conversation retrieved successfully",
  "data": {
    "id": 1,
    "title": "Project Team",
    "is_group": 1,
    "user_role": "member",
    "participant_count": 3,
    "last_read_message_id": 42
  }
}
```

### PUT /api/conversation/{id}

Update conversation details (admin only).

**Request Body:**

```json
{
  "name": "Updated Group Name",
"description": "Updated description"
}
```

**Response:**

```json
{
  "success": true,
  "message": "Conversation updated successfully",
  "data": {
    "id": 1,
    "title": "Updated Group Name",
    "is_group": 1,
    "user_role": "admin",
    "participant_count": 3,
    "last_read_message_id": 42
  }
}
```

### DELETE /api/conversation/{id}

Delete conversation (admin only).

**Response:**

```json
{
  "success": true,
  "message": "Conversation deleted successfully"
}
```

### GET /api/conversation/{id}/participants

Get conversation participants.

**Response:**

```json
{
  "success": true,
  "message": "Participants retrieved successfully",
  "data": [
    {
      "user_id": 1,
      "name": "Alice",
      "username": "alice",
      "email": "alice@example.com",
      "avatar_url": "/uploads/avatars/alice.jpg",
      "role": "admin",
      "joined_at": "2024-05-01T08:00:00Z"
    }
  ]
}
```

### POST /api/conversation/{id}/add-participants

Add participants to conversation (admin only).

**Request Body:**

```json
{
"user_ids": [5, 6, 7]
}
```

**Response:**

```json
{
  "success": true,
  "message": "Participants added successfully",
  "data": {
    "added_users": [5, 6, 7],
    "total_added": 3
  }
}
```

### POST /api/conversation/{id}/remove-participant

Remove participant from conversation.

**Request Body:**

```json
{
"user_id": 5
}
```

**Response:**

```json
{
  "success": true,
  "message": "Participant removed successfully"
}
```

### POST /api/conversation/{id}/mark-read

Mark conversation as read.

**Response:**

```json
{
  "success": true,
  "message": "Conversation marked as read"
}
```

---

## Message Management Endpoints

### GET /api/message

Get messages for a conversation.

**Query Parameters:**

- `conversation_id` (int): Required
- `page` (int): Page number
- `per_page` (int): Items per page (max: 100)
- `search` (string): Search within messages

**Response:**

```json
{
  "success": true,
  "message": "Messages retrieved successfully",
  "data": {
    "items": [
      {
        "id": 10,
        "conversation_id": 1,
        "sender_id": 2,
        "sender_name": "Bob",
        "sender_avatar": "/uploads/avatars/bob.jpg",
        "content": "Hello everyone!",
        "message_type": "text",
        "parent_id": null,
        "created_at": "2024-05-01T12:00:00Z",
        "reaction_count": 3,
        "reactions": "👍,❤️"
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total": 50,
      "total_pages": 3,
      "has_next": true,
      "has_prev": false
    }
  }
}
```

### POST /api/message

Send a new message.

**Request Body:**

```json
{
  "conversation_id": 1,
  "content": "Hello everyone!",
  "message_type": "text",
  "parent_id": null,
  "mentions": [2, 3],
  "attachments": [
    {
      "file_url": "/uploads/messages/file.pdf",
      "file_type": "application/pdf",
      "file_size": 102400,
      "original_name": "document.pdf"
    }
  ]
}
```

Each attachment object may include:

- `file_url` (string): Required file location
- `file_type` (string): Required MIME type
- `file_size` (int, optional): Size in bytes
- `original_name` (string, optional): Original filename
 
**Response:**

```json
{
  "success": true,
  "message": "Message sent successfully",
  "data": {
    "id": 101,
    "conversation_id": 1,
    "sender_id": 2,
    "content": "Hello everyone!",
    "message_type": "text",
    "parent_id": null,
    "created_at": "2024-05-01T12:00:00Z",
    "sender": {
      "id": 2,
      "name": "Bob",
      "avatar_url": "/uploads/avatars/bob.jpg"
    },
    "attachments": [],
    "reaction_count": 0
  }
}
```

### GET /api/message/{id}

Get specific message details.

**Response:**

```json
{
  "success": true,
  "message": "Message retrieved successfully",
  "data": {
    "id": 101,
    "conversation_id": 1,
    "sender_id": 2,
    "content": "Hello everyone!",
    "message_type": "text",
    "parent_id": null,
    "created_at": "2024-05-01T12:00:00Z",
    "sender": {
      "id": 2,
      "name": "Bob",
      "avatar_url": "/uploads/avatars/bob.jpg"
    },
    "attachments": [],
    "reaction_count": 1,
    "reactions": "👍"
  }
}
```

### PUT /api/message/{id}

Edit a message (sender only).

**Request Body:**

```json
{
"content": "Updated message content"
}
```

**Response:**

```json
{
  "success": true,
  "message": "Message updated successfully",
  "data": {
    "id": 101,
    "conversation_id": 1,
    "sender_id": 2,
    "content": "Updated message content",
    "message_type": "text",
    "parent_id": null,
    "created_at": "2024-05-01T12:00:00Z",
    "sender": {
      "id": 2,
      "name": "Bob",
      "avatar_url": "/uploads/avatars/bob.jpg"
    },
    "attachments": [],
    "reaction_count": 1
  }
}
```

### DELETE /api/message/{id}

Delete a message (sender only).

**Response:**

```json
{
  "success": true,
  "message": "Message deleted successfully"
}
```

### POST /api/message/{id}/reaction

Add/remove reaction to a message.

**Request Body:**

```json
{
"emoji": "👍"
}
```

**Response:**

```json
{
  "success": true,
  "message": "Reaction added successfully",
  "data": {
    "action": "added",
    "message_id": 101,
    "emoji": "👍"
  }
}
```

### POST /api/message/{id}/mark-read

Mark message as read.

**Response:**

```json
{
  "success": true,
  "message": "Message marked as read"
}
```

### POST /api/message/upload

Upload file for message attachment.

**Form Data:**

- `file`: File to upload (max 50MB)

**Response:**

```json
{
  "success": true,
  "message": "File uploaded successfully",
  "data": {
    "attachment_id": 1,
    "file_url": "/uploads/messages/file.pdf",
    "file_type": "application/pdf",
    "file_size": 102400,
    "original_name": "document.pdf"
  }
}
```

### GET /api/message/search

Search messages across all conversations.

**Query Parameters:**

- `q` (string): Search query
- `page` (int): Page number
- `per_page` (int): Items per page

**Response:**

```json
{
  "success": true,
  "message": "Messages found successfully",
  "data": {
    "items": [
      {
        "id": 10,
        "conversation_id": 1,
        "conversation_title": "Project Team",
        "sender_name": "Bob",
        "sender_avatar": "/uploads/avatars/bob.jpg",
        "content": "Hello",
        "created_at": "2024-05-01T12:00:00Z",
        "reaction_count": 1
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total": 30,
      "total_pages": 2,
      "has_next": true,
      "has_prev": false
    }
  }
}
```

---

## Chat Endpoints (Simplified)

### GET /api/chat/conversations

Get user's conversations with pagination.

**Query Parameters:**

- `page` (int): Page number
- `per_page` (int): Items per page

**Response:**

```json
{
  "success": true,
  "message": "Conversations retrieved successfully",
  "data": {
    "items": [
      {
        "id": 1,
        "title": "Project Team",
        "unread_count": 2
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total": 40,
      "total_pages": 2,
      "has_next": true,
      "has_prev": false
    }
  }
}
```

### POST /api/chat/send-message

Send a message. Optionally include a `temp_id` to correlate the server response and WebSocket event with a client-side placeholder.

**Request Body:**

```json
{
  "conversation_id": 1,
  "content": "Hello!",
  "message_type": "text",
  "mentions": [2],
  "temp_id": "123e4567"
}
```

**Response:**

```json
{
  "success": true,
  "message": "Message sent successfully",
  "data": {
    "message_id": 42,
    "temp_id": "123e4567"
  }
}
```

### GET /api/chat/messages

Get messages for conversation.

**Query Parameters:**

- `conversation_id` (int): Required
- `limit` (int): Max messages (default: 50, max: 100)
- `offset` (int): Skip messages (used with limit to calculate page)

**Response:**

```json
{
  "success": true,
  "message": "Messages retrieved successfully",
  "data": {
    "items": [
      {
        "id": 1,
        "conversation_id": 10,
        "sender_id": 2,
        "content": "Hello"
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 50,
      "total": 123,
      "total_pages": 3,
      "has_next": true,
      "has_prev": false
    }
  }
}
```

### POST /api/chat/create-conversation

Create new conversation.

### POST /api/chat/add-reaction

Add reaction to message.

### POST /api/chat/mark-as-read

Mark message as read.

### GET /api/chat/search-messages

Search messages.

### GET /api/chat/unread-count

Get unread message count.

### GET /api/chat/typing-status

Get typing status for conversation.

### POST /api/chat/set-typing

Set typing status.

### GET /api/chat/online-users

Get online users.

---

## Device Management Endpoints

### POST /api/device/register

Register device for push notifications.

**Request Body:**

```json
{
  "device_id": "device_123",
  "platform": "android",
  "fcm_token": "fcm_token_here",
  "app_version": "1.0.0",
  "device_name": "Samsung Galaxy S21",
  "os_version": "Android 11"
}
```

### GET /api/device

Get user's registered devices.

### PUT /api/device/{deviceId}

Update device information.

### DELETE /api/device/{deviceId}

Unregister device.

### POST /api/device/{deviceId}/ping

Update device last active timestamp.

### POST /api/device/test-notification

Send test push notification.

---

## Status Endpoints

### GET /api/status

API health check.

### GET /api/status/auth

Check authentication status.

### GET /api/status/database

Check database connection.

### GET /api/status/websocket

Check WebSocket server status.

---

## Response Format

### Success Response

```json
{
  "success": true,
  "message": "Operation successful",
  "data": {
    // Response data here
  }
}
```

### Paginated Response

```json
{
  "success": true,
  "message": "Data retrieved successfully",
  "data": {
    "items": [
      // Array of items
    ],
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

### Error Response

```json
{
  "success": false,
  "message": "Error message",
  "errors": {
    // Optional detailed errors
  }
}
```

---

## HTTP Status Codes

- `200` - Success
- `201` - Created
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `500` - Internal Server Error

---

## WebSocket Integration

The chat system also includes a WebSocket server for real-time messaging. WebSocket events include:

- **auth** - Authenticate WebSocket connection
- **message** - Send/receive messages
- **typing** - Typing indicators
- **reaction** - Message reactions
- **user_status** - User online/offline status

WebSocket server runs on `ws://localhost:8080` (configurable).

---

## Flutter Integration Notes

1. Use `dio` package for HTTP requests
2. Implement Bearer token authentication
3. Handle pagination for large data sets
4. Implement file upload for avatars and attachments
5. Use WebSocket for real-time features
6. Store auth token securely using `flutter_secure_storage`
7. Implement proper error handling for all API responses
