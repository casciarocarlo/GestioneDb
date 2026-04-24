# 🗄️ GestioneDb – Premium MySQL Database Manager

![Version](https://img.shields.io/badge/version-2.0.1-blue.svg)
![PHP](https://img.shields.io/badge/PHP-%3E%3D%207.4-777BB4.svg)
![MySQL](https://img.shields.io/badge/MySQL-%3E%3D%205.7-4479A1.svg)
![License](https://img.shields.io/badge/License-MIT-green.svg)

A comprehensive, zero-dependency PHP web application for managing MySQL databases, featuring a beautiful modern premium UI with dark mode and glassmorphism.

## ✨ Features

- **Premium UI**: Modern dark theme with seamless animations, sticky sidebars, and glassmorphism aesthetics.
- **Database Management**: Create, delete, and switch between multiple databases effortlessly.
- **Table Operations**: Build tables with extensive configurations, view structures, drop, and truncate.
- **Data Editing**: Browse data with pagination, insert new records, update or delete existing entries.
- **SQL Query Editor**: Run custom `SELECT`, `INSERT`, `UPDATE` queries with full execution feedback.
- **Built-in Authentication**: Multi-role support (Admin and User) with session management and activity tracking.
- **Dashboard & Stats**: Real-time counter animations and database health overviews.

## 🚀 Installation & Setup

1. **Clone the Repository**
   ```bash
   git clone https://github.com/yourusername/GestioneDb.git
   cd GestioneDb
   ```

2. **Environment Configuration**
   Copy the example environment file and configure it:
   ```bash
   cp .env.example .env
   # Edit .env with your favorite editor and set your DB connection details
   ```

3. **Database Initialization**
   Import the system authentication database using the provided schema:
   ```bash
   mysql -u root -p < schema.sql
   ```

4. **Serve the Application**
   Place it in your Apache/Nginx web root (e.g. `htdocs`, `www`) or use Laragon/XAMPP.
   Alternatively, test using the PHP built-in server:
   ```bash
   php -S localhost:8000
   ```
   Navigate to `http://localhost:8000/`.

## 🔒 Authentication

The application requires authentication out of the box. 
**Default Admin Credentials**:
- Username: `admin`
- Password: `admin123`

> [!WARNING]
> Change the default admin password immediately after your first login via the user management page or query execution.

## 📂 Project Structure

```text
GestioneDb/
├── assets/
│   └── css/
│       └── style.css       # Premium Dark Theme
├── backups/                # Local backup storage
├── exports/                # Exported SQL data
├── includes/
│   ├── header.php          # Reusable Sidebar UI
│   └── footer.php          # Scripts & Modals
├── logs/                   # System & Audit Logs
├── config.php              # Environment and DB config
├── schema.sql              # Core initial setup schema
├── index.php               # Dashboard
└── ... (other pages)
```

## 🤝 Contributing

We welcome pull requests! For major changes, please open an issue first to discuss what you would like to change.

1. Fork the Project
2. Create your Feature Branch (`git checkout -b feature/AmazingFeature`)
3. Commit your Changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the Branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
