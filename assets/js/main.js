/**
 * Veripool Reservation System - Main JavaScript
 */

// Debug mode
const DEBUG = true;

// Console logger
function logDebug(message, data = null) {
    if (DEBUG) {
        console.log(`[Veripool] ${message}`, data ? data : '');
    }
}

// Document ready
document.addEventListener('DOMContentLoaded', function() {
    logDebug('DOM loaded');
    
    // Initialize all components
    initDatePickers();
    initAvailabilityForm();
    initMobileMenu();
    initSmoothScroll();
});

// Date picker initialization
function initDatePickers() {
    const checkIn = document.getElementById('check_in');
    const checkOut = document.getElementById('check_out');
    
    if (checkIn && checkOut) {
        // Set min dates
        const today = new Date().toISOString().split('T')[0];
        checkIn.min = today;
        
        // Update check-out min date when check-in changes
        checkIn.addEventListener('change', function() {
            const checkInDate = new Date(this.value);
            checkInDate.setDate(checkInDate.getDate() + 1);
            const minCheckOut = checkInDate.toISOString().split('T')[0];
            
            checkOut.min = minCheckOut;
            
            // If current check-out is before new min, update it
            if (checkOut.value && checkOut.value < minCheckOut) {
                checkOut.value = minCheckOut;
            }
        });
        
        logDebug('Date pickers initialized');
    }
}

// Availability form handling
function initAvailabilityForm() {
    const form = document.querySelector('form[action*="check_availability"]');
    
    if (form) {
        form.addEventListener('submit', function(e) {
            const checkIn = document.getElementById('check_in').value;
            const checkOut = document.getElementById('check_out').value;
            
            if (!checkIn || !checkOut) {
                e.preventDefault();
                showAlert('Please select both check-in and check-out dates', 'warning');
                return false;
            }
            
            const start = new Date(checkIn);
            const end = new Date(checkOut);
            const nights = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
            
            if (nights < 1) {
                e.preventDefault();
                showAlert('Check-out date must be after check-in date', 'warning');
                return false;
            }
            
            logDebug('Availability check submitted', { checkIn, checkOut, nights });
        });
    }
}

// Mobile menu toggle
function initMobileMenu() {
    const menuToggle = document.createElement('button');
    menuToggle.className = 'mobile-menu-toggle';
    menuToggle.innerHTML = '<i class="fas fa-bars"></i>';
    menuToggle.style.display = 'none';
    
    const nav = document.querySelector('.navbar');
    const navLinks = document.querySelector('.nav-links');
    
    if (nav && navLinks) {
        // Add toggle button for mobile
        nav.insertBefore(menuToggle, navLinks);
        
        // Toggle menu on click
        menuToggle.addEventListener('click', function() {
            navLinks.classList.toggle('show');
            this.innerHTML = navLinks.classList.contains('show') ? 
                '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
        });
        
        // Show toggle button on mobile
        if (window.innerWidth <= 768) {
            menuToggle.style.display = 'block';
            navLinks.style.display = 'none';
        }
        
        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth <= 768) {
                menuToggle.style.display = 'block';
                if (!navLinks.classList.contains('show')) {
                    navLinks.style.display = 'none';
                }
            } else {
                menuToggle.style.display = 'none';
                navLinks.style.display = 'flex';
                navLinks.classList.remove('show');
            }
        });
        
        logDebug('Mobile menu initialized');
    }
}

// Smooth scroll for anchor links
function initSmoothScroll() {
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            
            if (href !== '#') {
                e.preventDefault();
                
                const target = document.querySelector(href);
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                    
                    // Update URL without jumping
                    history.pushState(null, null, href);
                }
            }
        });
    });
    
    logDebug('Smooth scroll initialized');
}

// Show alert message
function showAlert(message, type = 'info') {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type}`;
    alertDiv.innerHTML = message;
    alertDiv.style.position = 'fixed';
    alertDiv.style.top = '20px';
    alertDiv.style.right = '20px';
    alertDiv.style.zIndex = '9999';
    alertDiv.style.maxWidth = '300px';
    alertDiv.style.animation = 'slideIn 0.3s ease';
    
    document.body.appendChild(alertDiv);
    
    setTimeout(() => {
        alertDiv.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => {
            alertDiv.remove();
        }, 300);
    }, 5000);
    
    logDebug('Alert shown', { message, type });
}

// Format currency
function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

// Calculate nights between dates
function calculateNights(checkIn, checkOut) {
    const start = new Date(checkIn);
    const end = new Date(checkOut);
    return Math.ceil((end - start) / (1000 * 60 * 60 * 24));
}

// Add animation styles
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
    
    @media (max-width: 768px) {
        .nav-links {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #102C57;
            flex-direction: column;
            padding: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .nav-links.show {
            display: flex;
        }
        
        .mobile-menu-toggle {
            display: block;
            background: none;
            border: none;
            color: #FFCBCB;
            font-size: 1.5rem;
            cursor: pointer;
        }
    }
`;

document.head.appendChild(style);