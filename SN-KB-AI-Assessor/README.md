# AI Knowledge Base Assessor

A lightweight, PHP 8 web application for assessing and improving ServiceNow knowledge base articles using AI. Fully customizable for any organization through a web-based settings interface.

- Database-backed configuration
- Web-based settings management
- Customizable application name and branding
- Flexible assessment criteria via custom system prompts
- Support for any OpenAI-compatible LLM endpoint

## ⚠️ Important: Local Use Only

**This application is designed for local, single-user deployment only.** It should be run on your personal workstation (e.g., using XAMPP, WAMP, or similar local PHP server) and accessed via `localhost`.

**DO NOT deploy this application to a public web server or shared hosting environment.**

### Security Considerations

This application currently lacks several security features that would be required for multi-user or internet-facing deployment:

- **No Authentication**: There is no login system. Anyone who can access the application can view and modify all settings, including credentials.
- **Unencrypted Credentials**: ServiceNow passwords and API keys are stored in plaintext in the SQLite database. While the database is protected from web access via `.htaccess`, file system access provides full credential visibility.
- **No CSRF Protection**: The application does not implement Cross-Site Request Forgery protection.
- **No User Isolation**: All users share the same configuration and data.

---

## Prerequisites

- PHP 8.1+ with extensions:
  - SQLite (PDO)
  - cURL
  - JSON
- Local web server (XAMPP, WAMP, MAMP, or PHP's built-in server)
- OpenAI-compatible LLM API access:
  - Local: Ollama, LM Studio, etc.
  - Cloud: OpenRouter, OpenAI, Anthropic, etc.
- ServiceNow instance with API access

---

## Basic Setup (Local Deployment)

1. **Download files from the repository**
   - Clone or download this repository to your local machine

2. **Start your local web server**

   **Option A: Using PHP's built-in server (simplest)**
   ```bash
   cd path/to/SN-KB-AI-Assessor
   php -S localhost:8000
   ```

   **Option B: Using XAMPP/WAMP/MAMP**
   - Copy the application folder to your `htdocs` directory (XAMPP) or equivalent
   - Start Apache from the control panel
   - Ensure PHP 8.1+ is enabled

3. **Access the application**
   - Open your browser and navigate to:
     - PHP built-in server: `http://localhost:8000/index.php`
     - XAMPP/WAMP: `http://localhost/SN-KB-AI-Assessor/index.php`
   - You should see the application UI

4. **Configure settings**
   - Click the **Settings** link in the top right corner
   - Configure your ServiceNow connection details (instance URL, username, password)
   - Configure your LLM settings (API endpoint, model, API key if required)
   - Some sensible defaults have been added, but feel free to customize:
     - **Default query filter**: Fetches only published KB articles updated this week
     - Modify the query filter to target specific KBs or time periods as needed
   - Click **Save Settings**

5. **Start assessing**
   - Return to the main page
   - Click **Fetch KB Updates** to retrieve articles from ServiceNow
   - Select articles and click **Assess Selected** to analyze them

**Note:** Keep your browser window open while assessments are running. The application processes assessments in batches for better performance.

---

## Future Improvements

- **MySQL/MariaDB Support**: Add support for MySQL or MariaDB databases to improve concurrent performance. SQLite's database-level locking can create bottlenecks when processing multiple articles simultaneously. A client-server database would enable better concurrency and faster batch processing of knowledge base articles.

---