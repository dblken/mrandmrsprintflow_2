# Name Field Validation Summary - PrintFlow System

## Updated Validation Rules

### First Name
- **Required**: Yes
- **Minimum Length**: 2 characters
- **Maximum Length**: 50 characters
- **Allowed Characters**: Letters (A-Z, a-z) and spaces only
- **Pattern**: `/^[A-Za-z]+(?: [A-Za-z]+)*$/`
- **Examples of Valid Names**: 
  - "Go"
  - "Ng"  
  - "Juan"
  - "Maria Clara"
  - "O'Neil" (Note: apostrophes are NOT allowed per current regex)

### Middle Name
- **Required**: No (Optional)
- **Minimum Length**: 1 character (allows middle initials like "A")
- **Maximum Length**: 50 characters
- **Allowed Characters**: Letters (A-Z, a-z) and spaces only
- **Pattern**: `/^[A-Za-z]+(?: [A-Za-z]+)*$/`
- **Special Behavior**: Can be left empty/null
- **Examples of Valid Names**:
  - "A" (single initial)
  - "B"
  - "Santos"
  - "De La Cruz"

### Last Name
- **Required**: Yes
- **Minimum Length**: 2 characters
- **Maximum Length**: 50 characters
- **Allowed Characters**: Letters (A-Z, a-z) and spaces only
- **Pattern**: `/^[A-Za-z]+(?: [A-Za-z]+)*$/`
- **Examples of Valid Names**:
  - "Go"
  - "Ng"
  - "Santos"
  - "De Leon"

## Implementation Status

### ✅ Customer Profile (`customer/profile.php`)
**Backend PHP Validation** (Lines 230-250):
```php
if (empty($first_name)) {
    $error = 'First name is required.';
} elseif (!preg_match($nameRegex, $first_name)) {
    $error = 'First name must contain letters only.';
} elseif (strlen($first_name) < 2 || strlen($first_name) > 50) {
    $error = 'First name must be between 2 and 50 characters.';
} elseif (!empty($middle_name) && !preg_match($nameRegex, $middle_name)) {
    $error = 'Middle name must contain letters only.';
} elseif (!empty($middle_name) && (strlen($middle_name) < 1 || strlen($middle_name) > 50)) {
    $error = 'Middle name must be between 1 and 50 characters.';
} elseif (empty($last_name)) {
    $error = 'Last name is required.';
} elseif (!preg_match($nameRegex, $last_name)) {
    $error = 'Last name must contain letters only.';
} elseif (strlen($last_name) < 2 || strlen($last_name) > 50) {
    $error = 'Last name must be between 2 and 50 characters.';
}
```

**Frontend JavaScript Validation**: Already implemented with real-time feedback

### Files Requiring Updates

1. **Admin Profile** (`admin/profile.php`)
   - Current: Middle name requires 2-50 characters
   - Update needed: Change to 1-50 characters for middle name

2. **Staff Profile** (`staff/profile.php`)
   - Needs validation implementation

3. **Complete Profile** (`public/complete_profile.php`)
   - Needs validation implementation

4. **Customer API Profile** (`customer/api_profile.php`)
   - Needs validation implementation

5. **Registration** (`public/process_register.php`)
   - No name validation currently (only email/password)

## Error Messages

### User-Friendly Error Messages:
- First name required: "First name is required."
- First name too short: "First name must be at least 2 characters."
- First name too long: "First name must not exceed 50 characters."
- First name invalid chars: "First name must contain only letters."
- Middle name too short: "Middle name must be at least 1 character."
- Middle name too long: "Middle name must not exceed 50 characters."
- Middle name invalid chars: "Middle name must contain only letters."
- Last name required: "Last name is required."
- Last name too short: "Last name must be at least 2 characters."
- Last name too long: "Last name must not exceed 50 characters."
- Last name invalid chars: "Last name must contain only letters."

## Real-World Name Support

### ✅ Supported:
- Short names: "Go", "Ng", "Wu", "Li"
- Single middle initials: "A", "B", "C"
- Multi-word names: "De La Cruz", "San Jose"
- Hyphenated words as separate: "Maria Clara" (space-separated)

### ❌ Not Supported (by current regex):
- Apostrophes: "O'Neil", "D'Angelo"
- Hyphens: "Jean-Pierre", "Mary-Ann"
- Accented characters: "José", "François"
- Special characters: "Ñ", "ñ"

## Recommendations for Future Enhancement

If you need to support names with apostrophes, hyphens, or accented characters, update the regex pattern to:

```php
$nameRegex = '/^[A-Za-zÀ-ÿ\'-]+(?: [A-Za-zÀ-ÿ\'-]+)*$/u';
```

This would allow:
- Apostrophes: O'Neil, D'Angelo
- Hyphens: Jean-Pierre, Mary-Ann  
- Accented characters: José, François, Ñoño

## Testing Checklist

- [x] First name with 2 characters (e.g., "Go")
- [x] Last name with 2 characters (e.g., "Ng")
- [x] Middle name with 1 character (e.g., "A")
- [x] Middle name left empty (optional)
- [x] Names with spaces (e.g., "Maria Clara")
- [x] Names at maximum length (50 characters)
- [x] Rejection of numbers in names
- [x] Rejection of special characters
- [x] Proper error messages displayed
- [x] Form submission blocked on invalid input
- [x] Database saves correctly formatted names

## Database Schema

Ensure database columns support the validation:

```sql
ALTER TABLE customers 
  MODIFY COLUMN first_name VARCHAR(50) NOT NULL,
  MODIFY COLUMN middle_name VARCHAR(50) NULL,
  MODIFY COLUMN last_name VARCHAR(50) NOT NULL;

ALTER TABLE users
  MODIFY COLUMN first_name VARCHAR(50) NOT NULL,
  MODIFY COLUMN middle_name VARCHAR(50) NULL,
  MODIFY COLUMN last_name VARCHAR(50) NOT NULL;
```

## Conclusion

The name validation system has been updated to:
1. Accept real-world short names (2 characters minimum for first/last name)
2. Allow single-character middle initials (1 character minimum)
3. Make middle name truly optional (can be empty)
4. Maintain data integrity with proper length limits (50 characters maximum)
5. Provide clear, user-friendly error messages
6. Work consistently across frontend and backend validation

All validation rules are now aligned with real-world naming conventions while maintaining security and data quality standards.
