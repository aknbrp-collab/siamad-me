Here is a comprehensive, detailed project specification and prompt template designed from a client's perspective. You can hand this directly to a developer or use it as a foundational prompt for your AI coding assistant to build your **Mahad Aly Academic Information System (SIAKAD)** using Laravel and Livewire.

---

# Project Specification: Mahad Aly Academic Information System (SIAKAD)

## 1. Project Overview

We need a robust, modern web-based Academic Information System (SIAKAD) tailored specifically for **Mahad Aly** (Islamic Higher Education). The application must be built using **Laravel** (backend/framework) and **Livewire** (dynamic reactive frontend components) to ensure a fast, single-page-application-like user experience without complex API scaffolding.

---

## 2. Technology Stack & Environment

* **Backend:** Laravel 
* **Frontend UI:** Laravel Livewire, Tailwind CSS, Alpine.js (for smooth dropdowns, modals, and reactivity)
* **Database:** PostgreSQL



---

## 3. User Roles & Authentication Matrix

All users log in using a **Unique Identifier Number** and a **Password**.

| Role | Identifier (Login ID) | Description & Access Level |
| --- | --- | --- |
| **Superadmin** | Custom Username / ID | Full system access, database management, configuration, and role assignment. |
| **Admin** | Custom Username / ID | Manages daily operational data (students, teachers, classes, master data). |
| **Admin Payment** | Custom Username / ID | Manages student tuition fees (SPP), financial records, and scholarships. |
| **Academic Monitor** | Custom Username / ID | Monitors student attendance, academic progress, and daily activities. |
| **Teacher (Ustadz/Dosen)** | **NIDM** (Nomor Induk Dosen Mahad) | Manages assigned subjects, uploads materials, grades assignments, and hosts online classrooms. |
| **Student (Mahasantri)** | **NIM** (Nomor Induk Mahasantri) | Views schedules, submits assignments, takes quizzes, checks grades and payment status. |
| **Guardian (Wali Santri)** | **Unique Generated Code** | View-only access to their specific child's academic progress, attendance, and payment history. |

---

## 4. Core Modules & Functional Requirements

### A. Master Data & Academic Structure

* **Academic Year Management:** Define academic years (e.g., 2026/2027) and active status.
* **Semester Management:** Manage semesters (Odd / Even) linked to academic years.
* **Class Management (Kelas):** Group students by classes, levels, or dormitories.
* **Subject / Curriculum (Mata Kuliah / Kitab / Dars):** Manage courses taught at Mahad Aly.

### B. User Management

* CRUD operations for all user types.
* Bulk import/export features via Excel/CSV for students (NIM) and teachers (NIDM).
* Password reset and profile management.

### C. Financial & Scholarship Management (Keuangan & Beasiswa)

* **Payment Management (SPP & Lainnya):** Set billing structures per student/class/academic year, record cash/transfer payments, and print receipts (PDF).
* **Scholarship Management:** Assign scholarship quotas or fee waivers to specific students and track funding sources.
* Real-time payment status dashboard for Admin Payment and Guardians.
* roadmap payment : ** pay -> admin payment input data (status unverified) -> money convey to admin -> admin verified

### D. Online Classroom & Learning Management System (LMS)

* **Video Conference Integration:** Fields to input/share Zoom, Google Meet, or Jitsi links for live online sessions.
* **Assignments & Tasks:** Teachers can post assignments with deadlines; students can upload file submissions.
* **Quizzes:** Create basic online quizzes or exams.
* **Discussion Forums:** Class-specific chat/discussion threads for teacher-student interaction.

### E. Historical Data Retention (Crucial Requirement)

* **Zero Overwriting Policy:** When an academic year or semester changes, past records (grades, attendance, class assignments, financial history) **must remain intact and accessible**.
* Students and guardians must be able to look up historical report cards (KHS/Transkrip) from previous years seamlessly. Database relationships must use foreign keys tied to specific `academic_year_id` and `semester_id`.

---

## 5. Non-Functional Requirements

* **Responsive Design:** Must work seamlessly on desktop monitors, tablets, and mobile phones (mobile-friendly tables and navigation).
* **Security:** Role-based middleware to protect routes, CSRF protection, and secure password hashing (`Hash::make`).
* **Performance:** Pagination for large datasets (students, logs) and optimized Livewire queries.

---

