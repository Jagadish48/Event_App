# Network Events EMS — REST API Documentation

**Base URL:** `http://localhost/Backup_Files/api/`  
**Version:** 1.0.0  
**Auth:** Bearer Token (in `Authorization` header or `?api_token=` query param)

---

## Authentication

### POST /api/auth/login
Login and receive a Bearer token.

**Request Body (JSON):**
```json
{
  "email": "admin@example.com",
  "password": "secret",
  "token_name": "MyMobileApp"
}
```
**Response:**
```json
{
  "success": true,
  "data": {
    "token": "abc123...",
    "token_type": "Bearer",
    "expires_in": 2592000,
    "user": { "id": 1, "name": "Admin", "email": "admin@example.com", "role": "admin" }
  }
}
```

### POST /api/auth/logout
Invalidate the current token.  
**Headers:** `Authorization: Bearer <token>`

### GET /api/auth/me
Get current user info.  
**Headers:** `Authorization: Bearer <token>`

---

## Dashboard

### GET /api/dashboard
Role-aware summary statistics.  
**Headers:** `Authorization: Bearer <token>`

**Admin Response:**
```json
{
  "data": {
    "role": "admin",
    "employees": 15,
    "events_month": 3,
    "attendance_today": { "checked_in": 10, "checked_out": 2, "absent": 3, "late": 1 },
    "pending_approvals": {
      "expenses": 5, "expenses_amount": 4500.00,
      "profile_requests": 2, "password_resets": 1, "leave_requests": 3, "total": 11
    }
  }
}
```

**Employee Response:**
```json
{
  "data": {
    "role": "employee",
    "today_attendance": { "check_in": "09:15:00", "check_out": null, "status": "present" },
    "monthly_summary": { "present_days": 18, "absent_days": 1, "late_days": 2, "remaining_leaves": 2 },
    "pending_items": { "expenses": 1, "leave_requests": 0 }
  }
}
```

---

## Employees

### GET /api/employees
List all employees (admin) or own profile (employee).

| Param | Description |
|-------|-------------|
| (none) | Returns list |

### GET /api/employees/{id}
Get a single employee by ID.

### POST /api/employees *(admin only)*
Create a new employee.

**Request Body:**
```json
{
  "name": "Jane Doe",
  "email": "jane@example.com",
  "password": "secret123",
  "designation": "Event Coordinator",
  "department": "Events",
  "salary": 35000
}
```

### PUT /api/employees/{id} *(admin only)*
Update employee fields.

**Request Body (any combination):**
```json
{ "name": "Jane Smith", "designation": "Senior Coordinator", "salary": 40000 }
```

### DELETE /api/employees/{id} *(admin only)*
Remove employee record.

---

## Attendance

### GET /api/attendance
Get attendance records for the current month.

| Param | Description |
|-------|-------------|
| `month` | Format: `YYYY-MM`. Default: current month |
| `user_id` | Admin only: view specific employee |

### GET /api/attendance/status
Get today's attendance status for logged-in user.

**Response:**
```json
{
  "data": {
    "date": "2026-06-05",
    "status": "checked_in",
    "can_checkin": false,
    "can_checkout": true,
    "check_in": "09:05:22",
    "check_out": null
  }
}
```

### GET /api/attendance/summary
Get monthly policy summary.

| Param | Description |
|-------|-------------|
| `month` | Format: `YYYY-MM`. Default: current month |

### POST /api/attendance/checkin
Mark check-in for today.

**Request Body:**
```json
{ "latitude": "19.0760", "longitude": "72.8777", "address": "Mumbai, MH" }
```

### POST /api/attendance/checkout
Mark check-out for active session.

**Request Body:**
```json
{ "latitude": "19.0760", "longitude": "72.8777" }
```

### POST /api/attendance/absent
Mark yourself as absent for today.

**Request Body:**
```json
{
  "reason": "sick leave",
  "description": "Feeling unwell"
}
```
**Allowed reasons:** `sick leave`, `personal work`, `emergency`, `family function`, `not available`, `other`

---

## Expenses

### GET /api/expenses
List expenses.

| Param | Description |
|-------|-------------|
| `status` | Filter: `pending`, `approved`, `rejected` |
| `page` | Page number (default: 1) |
| `per_page` | Items per page (default: 20, max: 100) |

### GET /api/expenses/{id}
Get a single expense.

### POST /api/expenses
Submit a new expense.

**Request Body:**
```json
{
  "amount": 1500.00,
  "type": "Travel",
  "description": "Client site visit - auto fare",
  "date": "2026-06-05"
}
```

### PUT /api/expenses/{id} *(admin only)*
Approve or reject an expense.

**Request Body:**
```json
{ "action": "approve" }
```
```json
{ "action": "reject", "rejection_reason": "Receipt missing" }
```

### DELETE /api/expenses/{id} *(admin only)*
Delete an expense record.

---

## Leave Requests

### GET /api/leaves
List leave requests.

| Param | Description |
|-------|-------------|
| `status` | Filter: `pending`, `approved`, `rejected` |

### GET /api/leaves/{id}
Get a single leave request.

### POST /api/leaves
Submit a leave request.

**Request Body:**
```json
{
  "from_date": "2026-06-10",
  "to_date": "2026-06-12",
  "reason": "Family function"
}
```

### PUT /api/leaves/{id} *(admin only)*
Approve or reject a leave request.

**Request Body:**
```json
{ "action": "approve", "admin_note": "Enjoy!" }
```

---

## Error Responses

All errors return:
```json
{
  "success": false,
  "message": "Error description here",
  "data": null
}
```

| HTTP Status | Meaning |
|-------------|---------|
| 400 | Bad Request |
| 401 | Unauthorized (invalid/missing token) |
| 403 | Forbidden (insufficient role) |
| 404 | Not Found |
| 405 | Method Not Allowed |
| 409 | Conflict (duplicate/state error) |
| 422 | Unprocessable Entity (validation error) |
| 500 | Internal Server Error |

---

## Setup for Mobile App

1. **Add .htaccess** routing for `/Backup_Files/api/` to `index.php` — or call endpoints directly as PHP files.
2. **Login** with `POST /api/auth/login` to get a token.
3. **Store the token** securely (Keychain/EncryptedSharedPreferences).
4. **Send** `Authorization: Bearer <token>` with every subsequent request.
5. **Refresh** by calling login again before the 30-day expiry.

---

## Postman Collection Quick Start

```
POST http://localhost/Backup_Files/api/auth/login
Content-Type: application/json
{"email":"admin@yourapp.com","password":"yourpassword"}

# Then use returned token:
GET http://localhost/Backup_Files/api/dashboard
Authorization: Bearer <token>
```

---

*Generated for Network Events & Promotions EMS — June 2026*
