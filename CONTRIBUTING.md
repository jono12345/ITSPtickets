# Contributing to ITSPtickets

Thank you for considering contributing to ITSPtickets!

⚠️ **IMPORTANT**: This project is in **ALPHA/DEVELOPMENT stage** and is largely untested. Your contributions in testing, bug reporting, and fixing issues are especially valuable at this stage.

This project follows the **Simple Model** architecture philosophy, emphasizing simplicity, performance, and maintainability.

## 🏗️ Architecture Philosophy

### Simple Model Principles

✅ **Self-Contained Files**: Each file contains all necessary logic, database connections, and HTML output  
✅ **Direct Database Access**: PDO connections are established directly in each file  
✅ **Minimal Dependencies**: No complex autoloading or framework dependencies  
✅ **Easy Debugging**: All logic is visible and traceable within single files  
✅ **Fast Performance**: No overhead from MVC abstraction layers  

### What We Avoid

❌ **Complex Controller Classes**: No separate controller files  
❌ **Model Abstractions**: Direct SQL queries instead of ORM complexity  
❌ **Routing Complexity**: Simple file-based routing instead of complex routing systems  
❌ **Autoloader Dependencies**: Direct includes instead of class autoloading  

## 🚀 Getting Started

### Development Setup

1. **Fork the repository** on GitHub
2. **Clone your fork** locally:
```bash
git clone https://github.com/yourusername/ITSPtickets.git
cd ITSPtickets
```

3. **Set up development database**:
```bash
# Create database
mysql -u root -p -e "CREATE DATABASE ITSPtickets_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Import schema
mysql -u root -p ITSPtickets_dev < database/schema.sql
```

4. **Configure database**:
```bash
cp config/database.php.example config/database.php
# Edit with your development database credentials
```

5. **Set up web server** pointing to the project directory

6. **Run tests** to ensure everything works:
```bash
cd api/
./api-test-suite.sh
```

## 📝 Development Guidelines

### Code Standards

1. **File Naming Convention**
   - Core features: `feature-simple.php`
   - API endpoints: `api/feature.php`
   - Documentation: `FEATURE-NAME.md`

2. **File Structure Template**
```php
<?php
/*
|--------------------------------------------------------------------------
| Feature Name - Simple Model
|--------------------------------------------------------------------------
| Brief description of what this file does
*/

// 1. Authentication check (if required)
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /ITSPtickets/login.php');
    exit;
}

// 2. Database connection
$config = require 'config/database.php';
$dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
$pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);

// 3. Process requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle POST requests
}

// 4. Fetch data
try {
    // Database queries with prepared statements
    $stmt = $pdo->prepare("SELECT * FROM table WHERE condition = ?");
    $stmt->execute([$parameter]);
    $results = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    // Handle error appropriately
}

// 5. HTML output (if web interface)
?>
<!DOCTYPE html>
<html>
<!-- Your HTML here -->
</html>
```

3. **Security Requirements**
   - **Always use prepared statements** for database queries
   - **Escape HTML output** to prevent XSS
   - **Validate and sanitize input** on all user inputs
   - **Use CSRF protection** for forms
   - **Log security events** appropriately

4. **Database Access**
   - Use direct PDO connections (no ORM)
   - Always use prepared statements
   - Handle exceptions properly
   - Log database errors

## 🧪 Testing

### Before Submitting

⚠️ **Testing is Critical**: Since this project is largely untested, thorough testing is essential.

1. **Run the API test suite** (may reveal existing bugs):
```bash
cd api/
./api-test-suite.sh
```

2. **Test core functionality** (expect some features to be broken):
   - Login/logout
   - Create/update tickets
   - API endpoints
   - Database connections

3. **Cross-browser testing** (if UI changes):
   - Chrome 70+
   - Firefox 65+
   - Safari 12+
   - Edge 79+

### Test Coverage

⚠️ **Current Test Coverage is Minimal**: Help us improve by:
- **Reporting bugs** you find during testing
- **Documenting expected vs actual behavior**
- **Adding new test cases** for untested features
- **Verifying documentation accuracy** against actual functionality

Ensure your changes don't break existing functionality:
- All Simple Model files still accessible
- Database connections work
- Authentication flows function
- API endpoints respond correctly
- No broken links or missing resources

## 📋 Pull Request Process

### Before Creating a PR

1. **Create a feature branch**:
```bash
git checkout -b feature/your-feature-name
```

2. **Follow Simple Model patterns**
3. **Test thoroughly**
4. **Update documentation** if needed
5. **Ensure no sensitive data** is included

### PR Requirements

- ✅ **Descriptive title** and clear description
- ✅ **Test results** included in description
- ✅ **Screenshots** for UI changes
- ✅ **Documentation updates** if applicable
- ✅ **Simple Model compliance**

### PR Template

```markdown
## Description
Brief description of changes

## Type of Change
- [ ] Bug fix (non-breaking change that fixes an issue)
- [ ] New feature (non-breaking change that adds functionality)  
- [ ] Breaking change (fix or feature that would cause existing functionality to change)
- [ ] Documentation update

## Testing
- [ ] API test suite passes
- [ ] Manual testing completed
- [ ] Cross-browser testing (if UI changes)

## Simple Model Compliance
- [ ] Self-contained files
- [ ] Direct database access
- [ ] No framework dependencies
- [ ] Proper error handling
- [ ] Security best practices

## Screenshots (if applicable)

## Additional Notes
```

## 🎯 Feature Development

### Adding New Features

1. **Create new `feature-simple.php` file**
2. **Follow established patterns** for database connection
3. **Implement authentication checks** if required
4. **Add proper error handling and logging**
5. **Update navigation/routing** if needed
6. **Add tests** to verify functionality
7. **Document the feature**

### API Endpoints

For new API endpoints:
1. **Use RESTful conventions**
2. **Implement API key authentication**
3. **Return consistent JSON format**
4. **Add to API documentation**
5. **Include in test suite**
6. **Update Postman collection**

## 🐛 Bug Reports

### Before Reporting

1. **Search existing issues** to avoid duplicates
2. **Test with latest version**
3. **Check API test suite** results

### Bug Report Template

```markdown
**Describe the bug**
Clear description of what the bug is.

**To Reproduce**
Steps to reproduce the behavior:
1. Go to '...'
2. Click on '...'
3. See error

**Expected behavior**
What you expected to happen.

**Screenshots**
If applicable, add screenshots.

**Environment:**
- PHP Version: [e.g. 7.4]
- MySQL Version: [e.g. 5.7]
- Browser: [e.g. Chrome 90]
- Server: [e.g. Apache 2.4]

**Additional context**
Any other context about the problem.
```

## 📚 Documentation

### Documentation Standards

- **Clear, concise language**
- **Code examples** for complex features
- **Screenshots** for UI features
- **API documentation** for endpoints
- **Installation instructions** for setup

### Required Documentation Updates

When adding features, update:
- [ ] Main README.md
- [ ] API documentation (api-docs.php)
- [ ] Architecture documentation
- [ ] API testing guide
- [ ] Installation instructions (if applicable)

## 🎖️ Recognition

Contributors will be acknowledged in:
- GitHub contributors list
- Release notes for significant contributions
- Documentation credits

## 📞 Getting Help

- **GitHub Issues**: For bugs and feature requests
- **API Documentation**: `/api-docs.php` (after login)
- **Architecture Guide**: `SIMPLE-MODEL-ARCHITECTURE.md`
- **Testing Guide**: `api/API-TESTING.md`

## 📜 Code of Conduct

### Our Standards

- **Be respectful** and inclusive
- **Provide constructive feedback**
- **Focus on what is best** for the community
- **Show empathy** towards other community members

### Enforcement

Project maintainers have the right to remove, edit, or reject comments, commits, code, wiki edits, issues, and other contributions that are not aligned with this Code of Conduct.

---

**Thank you for contributing to ITSPtickets!** 🎉

Your contributions help make this project better for everyone while maintaining the simplicity and performance that makes it special.