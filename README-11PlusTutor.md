# 11 Plus Tutor - Premium Educational Platform

A modern, ultra-clean React (Vite) + TailwindCSS + Framer Motion educational platform for 11 Plus tutoring services.

## 🎨 Design Features

-   **Ultra-clean design** with vibrant gradient foundation
-   **Frosted-glass panels** with subtle motion accents
-   **Nunito & Poppins** font system with fallbacks
-   **Purple-themed** AI chat integration
-   **Responsive grid layouts** without clutter
-   **Premium animations** with Framer Motion

## 🎯 Color Palette

```javascript
const colors = {
    primary: "#370C85", // Rich purple
    accent: "#0B70FF", // Vivid blue
    "accent-soft": "#FFA99D", // Coral highlight
    "gray-900": "#111827", // Near-black headings
    "gray-600": "#4B5563", // Body text
    "gray-100": "#F5F7FC", // Light section background
    white: "#FFFFFF",
};
```

## 📁 Project Structure

```
src/
├── components/
│   ├── HeroSection.jsx          # Full-viewport hero with dynamic content
│   ├── AIChatBar.jsx           # Purple-themed collapsible chat
│   ├── InteractiveTiles.jsx    # Feature cards and test previews
│   ├── AboutSection.jsx        # Teacher profiles & reviews carousel
│   ├── PremiumCTAStrip.jsx     # Performance tracking & leaderboards
│   └── Footer.jsx              # Compact footer with navigation
├── pages/
│   └── Landing.jsx             # Main landing page assembly
├── data/
│   └── sampleData.js           # Sample data for components
└── README-11PlusTutor.md       # This documentation
```

## 🚀 Quick Start

### 1. Install Dependencies

Make sure you have the required dependencies in your `package.json`:

```json
{
    "dependencies": {
        "framer-motion": "^12.7.4",
        "@heroicons/react": "^2.2.0",
        "lucide-react": "^0.506.0"
    }
}
```

### 2. Update Tailwind Configuration

The `tailwind.config.js` has been updated with:

-   Custom color palette
-   Nunito & Poppins font families
-   Custom animations and keyframes
-   Gradient animations

### 3. Import and Use components

```jsx
import Landing from "./src/pages/Landing";
import { testimonials } from "./src/data/sampleData";

function App() {
    return <Landing testimonials={testimonials} />;
}
```

## 🧩 Component Overview

### HeroSection

-   **Full-viewport hero** with animated statistics
-   **Dynamic headline/subheading** with gradient text
-   **Two primary CTAs**: "Take a Practice Test" & "Find a Tutor"
-   **Floating testimonial cards** with rotation
-   **Success badges** and decorative elements

**Props:**

-   `testimonials` - Array of testimonial objects

### AIChatBar

-   **Purple-themed** collapsible chat widget
-   **Fixed positioning** with smooth animations
-   **Typing indicators** and message history
-   **Minimizable interface** with expand/collapse

**Features:**

-   Auto-focus on open
-   Simulated AI responses
-   Smooth animations with Framer Motion

### InteractiveTiles

-   **Key Features** - 4 interactive cards with hover effects
-   **Test Centre Preview** - Subject-specific test information
-   **Top Subjects** - Statistics and tutor availability
-   **How It Works** - 4-step process with connecting lines

**Interactions:**

-   Hover animations
-   Active state management
-   Responsive grid layouts

### AboutSection

-   **Teacher profile cards** with selection functionality
-   **Rotating reviews carousel** with navigation
-   **Detailed teacher information** with achievements
-   **Auto-playing testimonials** with manual controls

**Features:**

-   Teacher selection with detailed view
-   Review carousel with dots navigation
-   Verified review badges

### PremiumCTAStrip

-   **Performance tracking** feature showcase
-   **Leaderboards** and gamification elements
-   **Three action buttons**: Schedule, Apply, Contact
-   **Animated background** with floating particles

**Animations:**

-   Gradient background animation
-   Feature rotation
-   Particle effects

### Footer

-   **Compact design** with organized navigation
-   **Contact information** with interactive links
-   **Newsletter signup** with animated button
-   **Social media links** with hover effects

## 📊 Data Structure

### Testimonials

```javascript
{
  TestimonialID: number,
  UserName: string,
  Message: string,
  Rating: number (1-5),
  Status: "Approved" | "Pending" | "Rejected",
  Attachments: string | null
}
```

### Teachers

```javascript
{
  id: number,
  name: string,
  title: string,
  experience: string,
  specialties: string[],
  rating: number,
  students: number,
  image: string,
  bio: string,
  achievements: string[],
  location: string
}
```

## 🎨 Styling Guidelines

### Typography

-   **Headings**: Poppins font family
-   **Body text**: Nunito font family
-   **Responsive sizing**: `text-4xl md:text-6xl` pattern

### Colors

-   Use the defined color palette consistently
-   Gradients: `from-primary to-accent`
-   Glass effects: `bg-white/10 backdrop-blur-sm`

### Animations
<<<<<<< Updated upstream

-   **Entrance animations**: `whileInView` with `viewport={{ once: true }}`
-   **Hover effects**: `whileHover={{ scale: 1.05 }}`
-   **Stagger children**: Use `staggerChildren` for list animations
=======
- **Entrance animations**: `whileInView` with `viewport={{ once: true }}`
- **Hover effects**: `whilehover={{ scale: 1.05 }}`
- **Stagger children**: Use `staggerChildren` for list animations
>>>>>>> Stashed changes

### Responsive Design

-   **Mobile-first** approach
-   **Grid layouts**: `grid md:grid-cols-2 lg:grid-cols-4`
-   **Spacing**: Consistent padding and margins

## 🔧 Customization

### Adding New Sections

1. Create component in `src/components/`
2. Import in `src/pages/Landing.jsx`
3. Add to component order
4. Update sample data if needed

### Modifying Colors

Update `tailwind.config.js`:

```javascript
colors: {
  primary: '#YOUR_COLOR',
  accent: '#YOUR_COLOR',
  // ... other colors
}
```

### Adding Animations

```javascript
// In tailwind.config.js
keyframes: {
  'your-animation': {
    '0%': { /* start state */ },
    '100%': { /* end state */ }
  }
},
animation: {
  'your-animation': 'your-animation 2s ease-in-out infinite'
}
```

## 🚀 Integration with Backend

### API Endpoints Expected

-   `GET /api/testimonials` - Fetch approved testimonials
-   `GET /api/teachers` - Fetch teacher profiles
-   `GET /api/subjects` - Fetch subject information
-   `POST /api/chat` - AI chat functionality

### Data Fetching Example

```javascript
// In your Laravel controller or API
const testimonials = await fetch("/api/testimonials").then((res) => res.json());

return <Landing testimonials={testimonials} />;
```

## 📱 Responsive Breakpoints

-   **Mobile**: `< 768px`
-   **Tablet**: `768px - 1024px`
-   **Desktop**: `> 1024px`

All components are fully responsive with mobile-first design.

## 🎯 Performance Optimizations

-   **Lazy loading** with `whileInView`
-   **Memoized components** where appropriate
-   **Optimized images** with proper sizing
-   **Minimal re-renders** with proper state management

## 🔍 SEO Considerations

-   **Semantic HTML** structure
-   **Alt texts** for all images
-   **Proper heading hierarchy**
-   **Meta descriptions** (add to your layout)

## 🧪 Testing

### Component Testing

```javascript
import { render, screen } from "@testing-library/react";
import HeroSection from "./HeroSection";

test("renders hero section with testimonials", () => {
    const testimonials = [
        /* test data */
    ];
    render(<HeroSection testimonials={testimonials} />);
    expect(screen.getByText("Expert 11 Plus Tutoring")).toBeInTheDocument();
});
```

## 📈 Analytics Integration

Add tracking to key interactions:

-   CTA button clicks
-   Chat widget usage
-   Teacher profile views
-   Test starts

## 🔒 Security Notes

-   **Sanitize user inputs** in chat functionality
-   **Validate testimonials** before display
-   **Rate limit** API calls
-   **HTTPS only** for production

## 🚀 Deployment

1. Build the project: `npm run build`
2. Ensure all assets are properly linked
3. Configure your web server for SPA routing
4. Set up environment variables for API endpoints

## 📞 Support

For questions about this implementation:

-   Check component documentation
-   Review sample data structure
-   Test with provided sample data first
-   Ensure all dependencies are installed

---

**Built with ❤️ for premium educational experiences**
