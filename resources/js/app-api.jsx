import './bootstrap';
import '../css/app.css';
import React, { useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { ThemeProvider } from './contexts/ThemeContext';
import { AuthProvider } from './contexts/AuthContext';
import { ToastProvider } from './contexts/ToastContext';
import LoadingSpinner from './components/LoadingSpinner';
import { apiClient } from './api/client';
import { getToken } from './api/token';

const pageImports = import.meta.glob('./**/Pages/**/*.jsx');

const routes = [
  // Public + marketing
  { pattern: /^\/$/, component: './public/Pages/Main/Landing.jsx' },
  { pattern: /^\/welcome$/, component: './public/Pages/Main/Welcome.jsx' },
  { pattern: /^\/about$/, component: './public/Pages/Main/AboutUs.jsx' },
  { pattern: /^\/contact$/, component: './public/Pages/Main/ContactUs.jsx' },
  { pattern: /^\/widget-test$/, component: './public/Pages/Main/WidgetTestPage.jsx' },

  // Public catalog
  { pattern: /^\/services$/, component: './public/Pages/Services/Index.jsx' },
  { pattern: /^\/services\/([^/]+)$/, component: './public/Pages/Services/ShowPublic.jsx' },
  { pattern: /^\/articles$/, component: './public/Pages/Articles/IndexArticle.jsx' },
  { pattern: /^\/articles\/([^/]+)$/, component: './public/Pages/Articles/ShowArticle.jsx' },
  { pattern: /^\/subscription-plans$/, component: './public/Pages/Subscriptions/Catalog.jsx' },

  // Public applications
  { pattern: /^\/applications\/create$/, component: './public/Pages/Applications/CreateApplication.jsx' },
  { pattern: /^\/applications\/verify\/([^/]+)$/, component: './public/Pages/Applications/EmailVerified.jsx' },
  { pattern: /^\/application\/verification$/, component: './public/Pages/Applications/VerificationSent.jsx' },
  { pattern: /^\/email\/verified$/, component: './public/Pages/Applications/EmailVerified.jsx' },

  // Public auth
  { pattern: /^\/login$/, component: './public/Pages/Auth/PreLogin.jsx' },
  { pattern: /^\/authenticate-user$/, component: './public/Pages/Auth/Login.jsx' },
  { pattern: /^\/register$/, component: './admin/Pages/Auth/Register.jsx' },
  { pattern: /^\/forgot-password$/, component: './public/Pages/Auth/ForgotPassword.jsx' },
  { pattern: /^\/reset-password\/([^/]+)$/, component: './public/Pages/Auth/ResetPassword.jsx' },
  { pattern: /^\/verify-email$/, component: './public/Pages/Auth/VerifyEmail.jsx' },
  { pattern: /^\/confirm-password$/, component: './parent/Pages/Auth/ConfirmPassword.jsx' },
  { pattern: /^\/guest\/complete-profile$/, component: './public/Pages/Auth/GuestComplete.jsx' },

  // Teacher registration
  { pattern: /^\/teacher\/register$/, component: './public/Pages/Teacher/Register.jsx' },

  // Checkout + billing + transactions
  { pattern: /^\/checkout$/, component: './public/Pages/Checkout/Index.jsx' },
  { pattern: /^\/payment-widget$/, component: './public/Pages/Payments/PaymentWidget.jsx' },
  { pattern: /^\/billing\/setup$/, component: './public/Pages/Billing/Setup.jsx' },
  { pattern: /^\/billing\/pay\/([^/]+)$/, component: './public/Pages/Billing/PaymentPage.jsx' },
  { pattern: /^\/billing\/invoice$/, component: './public/Pages/Billing/CreateInvoicePage.jsx' },
  { pattern: /^\/billing\/subs$/, component: './public/Pages/Billing/SubscriptionPlansPage.jsx' },
  { pattern: /^\/billing\/receipt\/([^/]+)$/, component: './public/Pages/Billing/ReceiptPgae.jsx' },
  { pattern: /^\/billing\/portal$/, component: './public/Pages/Billing/CustomerPortalPage.jsx' },
  { pattern: /^\/transactions$/, component: './public/Pages/Transactions/Index.jsx' },
  { pattern: /^\/transactions\/(?!create$)([^/]+)$/, component: './public/Pages/Transactions/Show.jsx' },

  // Parent portal
  { pattern: /^\/portal$/, component: './parent/Pages/Main/Home.jsx' },
  { pattern: /^\/portal\/products$/, component: './parent/Pages/Products/Index.jsx' },
  { pattern: /^\/portal\/tracker$/, component: './parent/Pages/Main/ProgressTracker.jsx' },
  { pattern: /^\/portal\/assessments\/browse$/, component: './parent/Pages/Assessments/Browse.jsx' },
  { pattern: /^\/portal\/assessments$/, component: './parent/Pages/Assessments/Index.jsx' },
  { pattern: /^\/portal\/lessons\/browse$/, component: './parent/Pages/Lessons/Browse.jsx' },
  { pattern: /^\/portal\/lessons$/, component: './parent/Pages/Assessments/Index.jsx' },
  { pattern: /^\/portal\/submissions$/, component: './parent/Pages/Assessments/MySubmissions.jsx' },
  { pattern: /^\/portal\/schedule$/, component: './parent/Pages/Schedule/Schedule.jsx' },
  { pattern: /^\/portal\/calender$/, component: './parent/Pages/Schedule/Calender.jsx' },
  { pattern: /^\/portal\/deadlines$/, component: './parent/Pages/Schedule/Deadlines.jsx' },
  { pattern: /^\/portal\/transactions$/, component: './public/Pages/Transactions/Index.jsx' },
  { pattern: /^\/portal\/ai-hub$/, component: './parent/Pages/AI/AIHubDemo.jsx' },
  { pattern: /^\/portal\/ai-console$/, component: './parent/Pages/AI/AIConsolePage.jsx' },
  { pattern: /^\/portal\/journey$/, component: './parent/Pages/Journeys/Overview.jsx' },
  { pattern: /^\/portal\/feedback\/create$/, component: './parent/Pages/ParentFeedback/Create.jsx' },
  { pattern: /^\/portal\/services$/, component: './parent/Pages/Main/ProductsHub.jsx' },
  { pattern: /^\/portal\/services\/(\d+)$/, component: './parent/Pages/Services/Show.jsx' },
  { pattern: /^\/portal\/faqs$/, component: './parent/Pages/Main/Faq.jsx' },
  { pattern: /^\/portal\/notifications$/, component: './parent/Pages/Notifications/Index.jsx' },

  // Parent learning paths
  { pattern: /^\/courses\/my-courses$/, component: './parent/Pages/Courses/MyCourses.jsx' },
  { pattern: /^\/courses\/(\d+)$/, component: './parent/Pages/Courses/Show.jsx' },
  { pattern: /^\/courses$/, component: './parent/Pages/Courses/Browse.jsx' },
  { pattern: /^\/lessons\/(\d+)\/player$/, component: './parent/Pages/ContentLessons/Player.jsx' },
  { pattern: /^\/lessons\/(\d+)\/summary$/, component: './parent/Pages/ContentLessons/Summary.jsx' },
  { pattern: /^\/lessons\/(\d+)$/, component: './parent/Pages/Lessons/Show.jsx' },
  { pattern: /^\/lessons$/, component: './parent/Pages/Lessons/Index.jsx' },
  { pattern: /^\/assessments\/(\d+)\/attempt$/, component: './parent/Pages/Assessments/Attempt.jsx' },
  { pattern: /^\/assessments\/create$/, component: './admin/Pages/Assessments/Create.jsx' },
  { pattern: /^\/assessments\/(\d+)$/, component: './admin/Pages/Assessments/Show.jsx' },
  { pattern: /^\/assessments\/(\d+)\/edit$/, component: './admin/Pages/Assessments/Edit.jsx' },
  { pattern: /^\/assessments$/, component: './parent/Pages/Assessments/Index.jsx' },
  { pattern: /^\/submissions\/(\d+)$/, component: './parent/Pages/Submissions/Show.jsx' },
  { pattern: /^\/submissions$/, component: './parent/Pages/Assessments/MySubmissions.jsx' },


  // Admin submissions
  { pattern: /^\/admin\/submissions$/, component: './admin/Pages/Submissions/Index.jsx' },
  { pattern: /^\/admin\/submissions\/(\d+)$/, component: './admin/Pages/Submissions/AdminShow.jsx' },
  { pattern: /^\/admin\/submissions\/(\d+)\/grade$/, component: './admin/Pages/Submissions/Grade.jsx' },

  // Admin live sessions
  { pattern: /^\/admin\/live-sessions$/, component: './admin/Pages/LiveSessions/Index.jsx' },
  { pattern: /^\/admin\/live-sessions\/create$/, component: './admin/Pages/LiveSessions/Create.jsx' },
  { pattern: /^\/admin\/live-sessions\/(\d+)\/edit$/, component: './admin/Pages/LiveSessions/Edit.jsx' },
  { pattern: /^\/admin\/live-sessions\/(\d+)\/teach$/, component: './admin/Pages/Teacher/LiveLesson/TeacherPanel.jsx' },

  // Admin transactions
  { pattern: /^\/admin\/transactions$/, component: './admin/Pages/Transactions/Index.jsx' },

  // Admin portal feedback
  { pattern: /^\/admin\/portal-feedbacks$/, component: './admin/Pages/PortalFeedback/Index.jsx' },
  { pattern: /^\/admin\/portal-feedbacks\/(\d+)$/, component: './admin/Pages/PortalFeedback/Show.jsx' },

  // Admin feedback index (alias)
  { pattern: /^\/admin\/feedbacks$/, component: './admin/Pages/Feedback/IndexFeedback.jsx' },

  // Live sessions (parent)
  { pattern: /^\/live-sessions\/(\d+)\/join$/, component: './parent/Pages/ContentLessons/LivePlayer.jsx' },
  { pattern: /^\/live-sessions$/, component: './parent/Pages/LiveSessions/Browse.jsx' },
  { pattern: /^\/my-live-sessions\/(\d+)$/, component: './parent/Pages/LiveSessions/SessionDetails.jsx' },
  { pattern: /^\/my-live-sessions$/, component: './parent/Pages/LiveSessions/MySessions.jsx' },

  // Admin services
  { pattern: /^\/admin\/services$/, component: './admin/Pages/Services/Index.jsx' },
  { pattern: /^\/admin\/services\/create$/, component: './admin/Pages/Services/CreateService.jsx' },
  { pattern: /^\/admin\/services\/(\d+)$/, component: './admin/Pages/Services/Show.jsx' },
  { pattern: /^\/admin\/services\/(\d+)\/edit$/, component: './admin/Pages/Services/Edit.jsx' },

  // Admin feedbacks
  { pattern: /^\/feedbacks$/, component: './admin/Pages/Feedback/IndexFeedback.jsx' },
  { pattern: /^\/admin\/feedbacks\/create$/, component: './admin/Pages/Feedback/Create.jsx' },
  { pattern: /^\/feedbacks\/(\d+)$/, component: './admin/Pages/Feedback/ShowFeedback.jsx' },
  { pattern: /^\/feedbacks\/(\d+)\/edit$/, component: './admin/Pages/Feedback/EditFeedback.jsx' },
  { pattern: /^\/feedbacks\/(\d+)\/success$/, component: './admin/Pages/Feedback/Success.jsx' },

  // Admin transactions
  { pattern: /^\/transactions\/create$/, component: './admin/Pages/Transactions/Create.jsx' },

  // Admin tasks
  { pattern: /^\/admin-tasks$/, component: './admin/Pages/AdminTasks/Index.jsx' },
  { pattern: /^\/admin-tasks\/create$/, component: './admin/Pages/AdminTasks/Create.jsx' },
  { pattern: /^\/admin-tasks\/(\d+)$/, component: './admin/Pages/AdminTasks/Show.jsx' },
  { pattern: /^\/admin-tasks\/(\d+)\/edit$/, component: './admin/Pages/AdminTasks/Edit.jsx' },
  { pattern: /^\/teacher-student-assignments$/, component: './admin/Pages/TeacherStudentAssignments/Index.jsx' },


  // Teacher applications
  { pattern: /^\/teacher-applications$/, component: './admin/Pages/TeacherApplications/Index.jsx' },
  { pattern: /^\/admin\/teacher-applications$/, component: './admin/Pages/TeacherApplications/Index.jsx' },
  { pattern: /^\/superadmin\/teacher-applications$/, component: './admin/Pages/TeacherApplications/Index.jsx' },

  // Admin content management (courses)
  { pattern: /^\/admin\/courses$/, component: './admin/Pages/ContentManagement/Courses/Index.jsx' },
  { pattern: /^\/admin\/courses\/create$/, component: './admin/Pages/ContentManagement/Courses/Create.jsx' },
  { pattern: /^\/admin\/courses\/(\d+)\/edit$/, component: './admin/Pages/ContentManagement/Courses/Edit.jsx' },

  // Admin content management (content lessons)
  { pattern: /^\/admin\/content-lessons$/, component: './admin/Pages/ContentManagement/Lessons/Index.jsx' },
  { pattern: /^\/admin\/content-lessons\/create$/, component: './admin/Pages/ContentManagement/Lessons/Create.jsx' },
  { pattern: /^\/admin\/content-lessons\/(\d+)$/, component: './admin/Pages/ContentManagement/Lessons/Show.jsx' },
  { pattern: /^\/admin\/content-lessons\/(\d+)\/editform$/, component: './admin/Pages/ContentManagement/Lessons/Edit.jsx' },
  { pattern: /^\/admin\/content-lessons\/(\d+)\/edit$/, component: './admin/Pages/ContentManagement/Lessons/Edit.jsx' },
  { pattern: /^\/admin\/content-lessons\/(\d+)\/slides$/, component: './admin/Pages/ContentManagement/Lessons/SlideEditor.jsx' },

  // Admin dashboards
  { pattern: /^\/admin\/dashboards\/course-analytics$/, component: './admin/Pages/ContentManagement/Dashboards/Analytics.jsx' },

  // Admin questions
  { pattern: /^\/admin\/questions$/, component: './admin/Pages/Questions/Index.jsx' },
  { pattern: /^\/admin\/questions\/create$/, component: './admin/Pages/Questions/Create.jsx' },
  { pattern: /^\/admin\/questions\/(\d+)$/, component: './admin/Pages/Questions/Show.jsx' },
  { pattern: /^\/admin\/questions\/(\d+)\/edit$/, component: './admin/Pages/Questions/Edit.jsx' },

  // Admin articles
  { pattern: /^\/admin\/articles$/, component: './admin/Pages/Articles/Index.jsx' },
  { pattern: /^\/admin\/articles\/create$/, component: './admin/Pages/Articles/Create.jsx' },
  { pattern: /^\/articles\/(\d+)\/edit$/, component: './admin/Pages/Articles/EditArticle.jsx' },

  // Admin AI upload
  { pattern: /^\/admin\/ai-upload$/, component: './admin/Pages/AIUpload/Index.jsx' },
  { pattern: /^\/admin\/ai-upload\/(\d+)$/, component: './admin/Pages/AIUpload/Index.jsx' },
  { pattern: /^\/admin\/ai-upload\/(\d+)\/logs$/, component: './admin/Pages/AIUpload/Index.jsx' },

  // Admin applications
  { pattern: /^\/applications$/, component: './admin/Pages/Applications/IndexApplication.jsx' },
  { pattern: /^\/applications\/([^/]+)$/, component: './admin/Pages/Applications/ShowApplication.jsx' },
  { pattern: /^\/applications\/([^/]+)\/edit$/, component: './admin/Pages/Applications/EditApplication.jsx' },

  // Admin dashboard
  { pattern: /^\/admin-dashboard$/, component: './admin/Pages/Dashboard/AdminDashboard.jsx' },
  { pattern: /^\/admin-dashboard\/debug$/, component: './admin/Pages/Dashboard/AdminDashboard.jsx' },

  // Admin root routes (legacy)
  { pattern: /^\/subscriptions$/, component: './admin/Pages/Subscriptions/Index.jsx' },
  { pattern: /^\/subscriptions\/create$/, component: './admin/Pages/Subscriptions/Create.jsx' },
  { pattern: /^\/subscriptions\/(\d+)\/edit$/, component: './admin/Pages/Subscriptions/Edit.jsx' },
  { pattern: /^\/teachers$/, component: './admin/Pages/Teacher/Index.jsx' },
  { pattern: /^\/teachers\/create$/, component: './admin/Pages/Teacher/Create.jsx' },
  { pattern: /^\/teachers\/(\d+)$/, component: './admin/Pages/Teacher/Show.jsx' },
  { pattern: /^\/teachers\/(\d+)\/edit$/, component: './admin/Pages/Teacher/Edit.jsx' },
  { pattern: /^\/organizations$/, component: './admin/Pages/Organizations/Index.jsx' },
  { pattern: /^\/organizations\/create$/, component: './admin/Pages/Organizations/Create.jsx' },
  { pattern: /^\/organizations\/(\d+)$/, component: './admin/Pages/Organizations/Show.jsx' },
  { pattern: /^\/organizations\/(\d+)\/edit$/, component: './admin/Pages/Organizations/Edit.jsx' },
  { pattern: /^\/organizations\/(\d+)\/users$/, component: './admin/Pages/Organizations/Users.jsx' },
  { pattern: /^\/organizations\/(\d+)\/features$/, component: './admin/Pages/Organizations/Features.jsx' },

  { pattern: /^\/products$/, component: './admin/Pages/Products/Index.jsx' },
  { pattern: /^\/products\/create$/, component: './admin/Pages/Products/Create.jsx' },
  { pattern: /^\/products\/(\d+)$/, component: './admin/Pages/Products/Show.jsx' },
  { pattern: /^\/products\/(\d+)\/edit$/, component: './admin/Pages/Products/Edit.jsx' },
  { pattern: /^\/children$/, component: './admin/Pages/Children/Index.jsx' },
  { pattern: /^\/children\/create$/, component: './admin/Pages/Children/Create.jsx' },
  { pattern: /^\/children\/(\d+)$/, component: './admin/Pages/Children/Show.jsx' },
  { pattern: /^\/children\/(\d+)\/edit$/, component: './admin/Pages/Children/Edit.jsx' },
  { pattern: /^\/journeys$/, component: './admin/Pages/Journeys/Index.jsx' },
  { pattern: /^\/journeys\/create$/, component: './admin/Pages/Journeys/Create.jsx' },
  { pattern: /^\/journeys\/overview$/, component: './admin/Pages/Journeys/Index.jsx' },
  { pattern: /^\/journeys\/(\d+)$/, component: './admin/Pages/Journeys/Show.jsx' },
  { pattern: /^\/journey-categories$/, component: './admin/Pages/JourneyCategories/Index.jsx' },
  { pattern: /^\/journey-categories\/create$/, component: './admin/Pages/JourneyCategories/Create.jsx' },
  { pattern: /^\/attendance$/, component: './admin/Pages/Attendance/Overview.jsx' },
  { pattern: /^\/attendance\/lesson\/(\d+)$/, component: './admin/Pages/Attendance/Sheet.jsx' },
  { pattern: /^\/homework$/, component: './admin/Pages/Homework/Index.jsx' },
  { pattern: /^\/homework\/create$/, component: './admin/Pages/Homework/Create.jsx' },
  { pattern: /^\/homework\/(\d+)$/, component: './admin/Pages/Homework/Show.jsx' },
  { pattern: /^\/homework\/(\d+)\/edit$/, component: './admin/Pages/Homework/Edit.jsx' },
  { pattern: /^\/admin\/homework$/, component: './admin/Pages/Homework/Index.jsx' },
  { pattern: /^\/admin\/homework\/create$/, component: './admin/Pages/Homework/Create.jsx' },
  { pattern: /^\/admin\/homework\/(\d+)$/, component: './admin/Pages/Homework/Show.jsx' },
  { pattern: /^\/admin\/homework\/(\d+)\/edit$/, component: './admin/Pages/Homework/Edit.jsx' },
  { pattern: /^\/homework\/submissions$/, component: './admin/Pages/HomeworkSubmissions/Index.jsx' },
  { pattern: /^\/homework\/submissions\/(\d+)$/, component: './admin/Pages/HomeworkSubmissions/Show.jsx' },
  { pattern: /^\/homework\/submissions\/(\d+)\/edit$/, component: './admin/Pages/HomeworkSubmissions/Edit.jsx' },
  { pattern: /^\/admin\/homework-submissions$/, component: './admin/Pages/HomeworkSubmissions/Index.jsx' },
  { pattern: /^\/admin\/homework-submissions\/(\d+)$/, component: './admin/Pages/HomeworkSubmissions/Show.jsx' },
  { pattern: /^\/admin\/homework-submissions\/(\d+)\/edit$/, component: './admin/Pages/HomeworkSubmissions/Edit.jsx' },
  { pattern: /^\/notifications$/, component: './admin/Pages/Notifications/Index.jsx' },
  { pattern: /^\/notifications\/create$/, component: './admin/Pages/Notifications/Create.jsx' },
  { pattern: /^\/notifications\/(\d+)$/, component: './admin/Pages/Notifications/Show.jsx' },
  { pattern: /^\/notifications\/(\d+)\/edit$/, component: './admin/Pages/Notifications/Edit.jsx' },
  { pattern: /^\/tasks$/, component: './admin/Pages/Tasks/Index.jsx' },
  { pattern: /^\/tasks\/create$/, component: './admin/Pages/Tasks/Create.jsx' },
  { pattern: /^\/tasks\/(\d+)$/, component: './admin/Pages/Tasks/Show.jsx' },
  { pattern: /^\/tasks\/(\d+)\/edit$/, component: './admin/Pages/Tasks/Edit.jsx' },
  { pattern: /^\/feedbacks$/, component: './admin/Pages/Feedback/IndexFeedback.jsx' },
  { pattern: /^\/feedbacks\/(\d+)$/, component: './admin/Pages/Feedback/ShowFeedback.jsx' },
  { pattern: /^\/feedbacks\/(\d+)\/edit$/, component: './admin/Pages/Feedback/EditFeedback.jsx' },
  { pattern: /^\/faqs$/, component: './admin/Pages/Faqs/IndexFaq.jsx' },
  { pattern: /^\/faqs\/([^\/]+)$/, component: './admin/Pages/Faqs/ShowFaq.jsx' },
  { pattern: /^\/faqs\/([^\/]+)\/edit$/, component: './admin/Pages/Faqs/EditFaq.jsx' },
  { pattern: /^\/alerts$/, component: './admin/Pages/Alerts/IndexAlert.jsx' },
  { pattern: /^\/alerts\/(\d+)$/, component: './admin/Pages/Alerts/ShowAlert.jsx' },
  { pattern: /^\/alerts\/(\d+)\/edit$/, component: './admin/Pages/Alerts/EditAlert.jsx' },
  { pattern: /^\/slides$/, component: './admin/Pages/Slides/IndexSlide.jsx' },
  { pattern: /^\/slides\/(\d+)$/, component: './admin/Pages/Slides/ShowSlide.jsx' },
  { pattern: /^\/slides\/(\d+)\/edit$/, component: './admin/Pages/Slides/EditSlide.jsx' },
  { pattern: /^\/milestones$/, component: './admin/Pages/Milestones/Index.jsx' },
  { pattern: /^\/milestones\/create$/, component: './admin/Pages/Milestones/Create.jsx' },
  { pattern: /^\/milestones\/(\d+)\/edit$/, component: './admin/Pages/Milestones/Edit.jsx' },
  { pattern: /^\/testimonials$/, component: './admin/Pages/Testimonials/Index.jsx' },
  { pattern: /^\/testimonials\/(\d+)$/, component: './admin/Pages/Testimonials/Show.jsx' },
  { pattern: /^\/testimonials\/(\d+)\/edit$/, component: './admin/Pages/Testimonials/Edit.jsx' },
  { pattern: /^\/admin\/testimonials$/, component: './admin/Pages/Testimonials/Index.jsx' },
  { pattern: /^\/admin\/testimonials\/(\d+)$/, component: './admin/Pages/Testimonials/Show.jsx' },
  { pattern: /^\/admin\/testimonials\/(\d+)\/edit$/, component: './admin/Pages/Testimonials/Edit.jsx' },
  { pattern: /^\/user-subscriptions$/, component: './admin/Pages/UserSubscriptions/Index.jsx' },
  { pattern: /^\/user-subscriptions\/grant$/, component: './admin/Pages/UserSubscriptions/GrantSubscription.jsx' },
  { pattern: /^\/users\/(\d+)\/subscriptions$/, component: './admin/Pages/UserSubscriptions/Show.jsx' },
  { pattern: /^\/access$/, component: './admin/Pages/access/Index.jsx' },
  { pattern: /^\/questions$/, component: './admin/Pages/Questions/Index.jsx' },
  { pattern: /^\/questions\/create$/, component: './admin/Pages/Questions/Create.jsx' },
  { pattern: /^\/questions\/(\d+)$/, component: './admin/Pages/Questions/Show.jsx' },
  { pattern: /^\/questions\/(\d+)\/edit$/, component: './admin/Pages/Questions/Edit.jsx' },

  // Admin assessments + lessons (shared for admin/teacher)
  { pattern: /^\/admin\/assessments$/, component: './admin/Pages/Assessments/Index.jsx' },
  { pattern: /^\/admin\/assessments\/create$/, component: './admin/Pages/Assessments/Create.jsx' },
  { pattern: /^\/admin\/assessments\/(\d+)$/, component: './admin/Pages/Assessments/Show.jsx' },
  { pattern: /^\/admin\/assessments\/(\d+)\/edit$/, component: './admin/Pages/Assessments/Edit.jsx' },
  { pattern: /^\/admin\/lessons$/, component: './admin/Pages/Lessons/Index.jsx' },
  { pattern: /^\/admin\/lessons\/create$/, component: './admin/Pages/Lessons/Create.jsx' },
  { pattern: /^\/admin\/lessons\/(\d+)$/, component: './admin/Pages/Lessons/Show.jsx' },
  { pattern: /^\/admin\/lessons\/(\d+)\/edit$/, component: './admin/Pages/Lessons/Edit.jsx' },
  { pattern: /^\/admin\/assigned-lessons$/, component: './admin/Pages/Lessons/AssignedLessons.jsx' },
  { pattern: /^\/admin\/notifications$/, component: './admin/Pages/Notifications/Index.jsx' },
  { pattern: /^\/admin\/notifications\/create$/, component: './admin/Pages/Notifications/Create.jsx' },
  { pattern: /^\/admin\/notifications\/(\d+)$/, component: './admin/Pages/Notifications/Show.jsx' },
  { pattern: /^\/admin\/notifications\/(\d+)\/edit$/, component: './admin/Pages/Notifications/Edit.jsx' },
  { pattern: /^\/admin\/tasks$/, component: './admin/Pages/Tasks/Index.jsx' },
  { pattern: /^\/admin\/tasks\/create$/, component: './admin/Pages/Tasks/Create.jsx' },
  { pattern: /^\/admin\/tasks\/(\d+)$/, component: './admin/Pages/Tasks/Show.jsx' },
  { pattern: /^\/admin\/tasks\/(\d+)\/edit$/, component: './admin/Pages/Tasks/Edit.jsx' },
  { pattern: /^\/admin\/feedbacks\/create$/, component: './admin/Pages/Feedback/Create.jsx' },
  { pattern: /^\/admin\/faqs\/create$/, component: './admin/Pages/Faqs/CreateFaq.jsx' },
  { pattern: /^\/admin\/alerts\/create$/, component: './admin/Pages/Alerts/CreateAlert.jsx' },
  { pattern: /^\/admin\/slides\/create$/, component: './admin/Pages/Slides/CreateSlide.jsx' },
  { pattern: /^\/admin\/testimonials\/create$/, component: './admin/Pages/Testimonials/Create.jsx' },

  // Teacher portal (admin pages scoped by role)
  { pattern: /^\/teacher\/dashboard$/, component: './admin/Pages/Teacher/Dashboard.jsx' },
  { pattern: /^\/teacher\/students$/, component: './admin/Pages/Teacher/Students/Index.jsx' },
  { pattern: /^\/teacher\/students\/(\d+)$/, component: './admin/Pages/Teacher/Students/Show.jsx' },
  { pattern: /^\/teacher\/tasks$/, component: './admin/Pages/Teacher/Tasks/Index.jsx' },
  { pattern: /^\/teacher\/tasks\/(\d+)$/, component: './admin/Pages/Teacher/Tasks/Show.jsx' },
  { pattern: /^\/teacher\/revenue$/, component: './admin/Pages/Teacher/Revenue/Index.jsx' },
  { pattern: /^\/teacher\/lesson-uploads$/, component: './admin/Pages/Teacher/Uploads/Pending.jsx' },
  { pattern: /^\/teacher\/lesson-uploads\/(\d+)$/, component: './admin/Pages/Teacher/Uploads/Pending.jsx' },
  { pattern: /^\/teacher\/ai-upload$/, component: './admin/Pages/AIUpload/Index.jsx' },
  { pattern: /^\/teacher\/lesson-uploads\/pending$/, component: './admin/Pages/Teacher/Uploads/Pending.jsx' },
  { pattern: /^\/teacher\/assigned-lessons$/, component: './admin/Pages/Lessons/AssignedLessons.jsx' },
  { pattern: /^\/teacher\/content-lessons$/, component: './admin/Pages/ContentManagement/Lessons/Index.jsx' },
  { pattern: /^\/teacher\/content-lessons\/create$/, component: './admin/Pages/ContentManagement/Lessons/Create.jsx' },
  { pattern: /^\/teacher\/content-lessons\/(\d+)$/, component: './admin/Pages/ContentManagement/Lessons/Show.jsx' },
  { pattern: /^\/teacher\/content-lessons\/(\d+)\/editform$/, component: './admin/Pages/ContentManagement/Lessons/Edit.jsx' },
  { pattern: /^\/teacher\/content-lessons\/(\d+)\/edit$/, component: './admin/Pages/ContentManagement/Lessons/Edit.jsx' },
  { pattern: /^\/teacher\/content-lessons\/(\d+)\/slides$/, component: './admin/Pages/ContentManagement/Lessons/SlideEditor.jsx' },
  { pattern: /^\/teacher\/courses$/, component: './admin/Pages/ContentManagement/Courses/Index.jsx' },
  { pattern: /^\/teacher\/courses\/create$/, component: './admin/Pages/ContentManagement/Courses/Create.jsx' },
  { pattern: /^\/teacher\/courses\/(\d+)\/edit$/, component: './admin/Pages/ContentManagement/Courses/Edit.jsx' },
  { pattern: /^\/teacher\/live-sessions$/, component: './admin/Pages/LiveSessions/Index.jsx' },
  { pattern: /^\/teacher\/live-sessions\/create$/, component: './admin/Pages/LiveSessions/Create.jsx' },
  { pattern: /^\/teacher\/live-sessions\/(\d+)\/edit$/, component: './admin/Pages/LiveSessions/Edit.jsx' },
  { pattern: /^\/teacher\/attendance$/, component: './admin/Pages/Attendance/Overview.jsx' },
  { pattern: /^\/teacher\/attendance\/lesson\/(\d+)$/, component: './admin/Pages/Attendance/Sheet.jsx' },
  { pattern: /^\/teacher\/lessons$/, component: './admin/Pages/Lessons/Index.jsx' },
  { pattern: /^\/teacher\/lessons\/create$/, component: './admin/Pages/Lessons/Create.jsx' },
  { pattern: /^\/teacher\/lessons\/(\d+)$/, component: './admin/Pages/Lessons/Show.jsx' },
  { pattern: /^\/teacher\/lessons\/(\d+)\/edit$/, component: './admin/Pages/Lessons/Edit.jsx' },
  { pattern: /^\/teacher\/assessments$/, component: './admin/Pages/Assessments/Index.jsx' },
  { pattern: /^\/teacher\/assessments\/create$/, component: './admin/Pages/Assessments/Create.jsx' },
  { pattern: /^\/teacher\/assessments\/(\d+)$/, component: './admin/Pages/Assessments/Show.jsx' },
  { pattern: /^\/teacher\/assessments\/(\d+)\/edit$/, component: './admin/Pages/Assessments/Edit.jsx' },
  { pattern: /^\/teacher\/questions$/, component: './admin/Pages/Questions/Index.jsx' },
  { pattern: /^\/teacher\/questions\/create$/, component: './admin/Pages/Questions/Create.jsx' },
  { pattern: /^\/teacher\/questions\/(\d+)$/, component: './admin/Pages/Questions/Show.jsx' },
  { pattern: /^\/teacher\/questions\/(\d+)\/edit$/, component: './admin/Pages/Questions/Edit.jsx' },
  { pattern: /^\/teacher\/live-sessions\/(\d+)\/teach$/, component: './admin/Pages/Teacher/LiveLesson/TeacherPanel.jsx' },

  // Superadmin portal
  { pattern: /^\/superadmin\/dashboard$/, component: './superadmin/Pages/Dashboard/Index.jsx' },
  { pattern: /^\/superadmin\/analytics$/, component: './superadmin/Pages/Analytics/Index.jsx' },
  { pattern: /^\/superadmin\/analytics\/dashboard$/, component: './superadmin/Pages/Analytics/Index.jsx' },
  { pattern: /^\/superadmin\/organizations$/, component: './superadmin/Pages/Organizations/Index.jsx' },
  { pattern: /^\/superadmin\/organizations\/create$/, component: './superadmin/Pages/Organizations/Create.jsx' },
  { pattern: /^\/superadmin\/organizations\/(\d+)$/, component: './superadmin/Pages/Organizations/Show.jsx' },
  { pattern: /^\/superadmin\/organizations\/(\d+)\/edit$/, component: './superadmin/Pages/Organizations/Edit.jsx' },
  { pattern: /^\/superadmin\/organizations\/(\d+)\/branding$/, component: './superadmin/Pages/Organizations/Branding.jsx' },
  { pattern: /^\/superadmin\/users$/, component: './superadmin/Pages/Users/Index.jsx' },
  { pattern: /^\/superadmin\/users\/create$/, component: './superadmin/Pages/Users/Create.jsx' },
  { pattern: /^\/superadmin\/users\/(\d+)$/, component: './superadmin/Pages/Users/Show.jsx' },
  { pattern: /^\/superadmin\/users\/(\d+)\/edit$/, component: './superadmin/Pages/Users/Edit.jsx' },
  { pattern: /^\/superadmin\/site-admin$/, component: './superadmin/Pages/SiteAdmin/Index.jsx' },
  { pattern: /^\/superadmin\/content\/courses$/, component: './superadmin/Pages/Content/AllCourses.jsx' },
  { pattern: /^\/superadmin\/content\/lessons$/, component: './superadmin/Pages/Content/AllLessons.jsx' },
];

const notFoundComponent = './Pages/Errors/404.jsx';

const normalizeBasePath = (basePath) => {
  if (!basePath || basePath === '/') return '';
  return basePath.endsWith('/') ? basePath.slice(0, -1) : basePath;
};

const normalizePath = (path) => {
  if (!path) return '/';
  const withSlash = path.startsWith('/') ? path : `/${path}`;
  if (withSlash.length > 1 && withSlash.endsWith('/')) {
    return withSlash.slice(0, -1);
  }
  return withSlash;
};

const getPath = (basePath) => {
  const rawPath = window.location.pathname || '/';
  const normalizedBase = normalizeBasePath(basePath);
  if (!normalizedBase) return normalizePath(rawPath);
  if (rawPath === normalizedBase) return '/';
  if (rawPath.startsWith(`${normalizedBase}/`)) {
    return normalizePath(rawPath.slice(normalizedBase.length));
  }
  return normalizePath(rawPath);
};

const matchRoute = (path) => {
  const normalized = normalizePath(path);
  for (const route of routes) {
    const match = normalized.match(route.pattern);
    if (match) {
      return {
        component: route.component,
        params: match.slice(1),
      };
    }
  }
  return { component: notFoundComponent, params: [] };
};

const loadComponent = async (componentPath) => {
  const loader = pageImports[componentPath];
  if (!loader) return null;
  const module = await loader();
  return module?.default || null;
};

const AppShell = ({ basePath }) => {
  const [currentPath, setCurrentPath] = useState(getPath(basePath));
  const [Component, setComponent] = useState(null);
  const [loading, setLoading] = useState(true);

  const match = useMemo(() => matchRoute(currentPath), [currentPath]);

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    loadComponent(match.component)
      .then((LoadedComponent) => {
        if (!mounted) return;
        setComponent(() => LoadedComponent);
      })
      .finally(() => {
        if (mounted) setLoading(false);
      });

    return () => {
      mounted = false;
    };
  }, [match.component]);

  useEffect(() => {
    const onPopState = () => {
      setCurrentPath(getPath(basePath));
    };
    window.addEventListener('popstate', onPopState);
    return () => window.removeEventListener('popstate', onPopState);
  }, [basePath]);

  if (loading || !Component) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <LoadingSpinner size="lg" color="blue" />
      </div>
    );
  }

  const page = <Component routeParams={match.params} />;
  return Component.layout ? Component.layout(page) : page;
};

const App = ({ basePath }) => {
  const [organizationBranding, setOrganizationBranding] = useState(null);
  const [brandingLoaded, setBrandingLoaded] = useState(false);

  useEffect(() => {
    const fetchOrganizationBranding = async () => {
      try {
        const token = getToken();
        if (!token) {
          setBrandingLoaded(true);
          return;
        }

        const response = await apiClient.get('/me', { useToken: true });
        const userData = response?.data?.user ?? response?.data;
        
        // Extract organization branding from user data
        // The branding might be in user.organization.settings.branding
        const branding = userData?.organization?.settings?.branding || null;
        
        console.log('🎨 Fetched organization branding:', branding);
        setOrganizationBranding(branding);
      } catch (error) {
        console.error('Failed to fetch organization branding:', error);
      } finally {
        setBrandingLoaded(true);
      }
    };

    fetchOrganizationBranding();
  }, []);

  if (!brandingLoaded) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <LoadingSpinner size="lg" color="blue" />
      </div>
    );
  }

  return (
    <ThemeProvider organizationBranding={organizationBranding}>
      <AuthProvider>
        <ToastProvider>
          <AppShell basePath={basePath} />
        </ToastProvider>
      </AuthProvider>
    </ThemeProvider>
  );
};

const mount = () => {
  const el = document.getElementById('app');
  if (!el) return;

  const basePath = normalizeBasePath(el.getAttribute('data-base-path') || '');
  window.__API_APP_BASE__ = basePath;

  createRoot(el).render(<App basePath={basePath} />);
};

mount();
