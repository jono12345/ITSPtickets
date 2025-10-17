/**
 * Real-time Notifications Client
 * Handles Server-Sent Events for live updates
 */
class RealTimeNotifications {
    constructor(options = {}) {
        this.options = {
            endpoint: '/ITSPtickets/realtime-notifications.php',
            reconnectInterval: 5000, // 5 seconds
            maxReconnectAttempts: 10,
            enableSound: true,
            enableToasts: true,
            enableBadges: true,
            ...options
        };
        
        this.eventSource = null;
        this.reconnectAttempts = 0;
        this.isConnected = false;
        this.notifications = [];
        
        this.init();
    }
    
    init() {
        this.createNotificationContainer();
        this.loadSounds();
        this.connect();
        this.bindEvents();
    }
    
    connect() {
        try {
            this.eventSource = new EventSource(this.options.endpoint);
            
            this.eventSource.onopen = () => {
                console.log('✅ Real-time notifications connected');
                this.isConnected = true;
                this.reconnectAttempts = 0;
                this.updateConnectionStatus(true);
            };
            
            this.eventSource.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);
                    this.handleNotificationData(data);
                } catch (error) {
                    console.error('Failed to parse notification data:', error);
                }
            };
            
            this.eventSource.onerror = (error) => {
                console.warn('⚠️ Notification connection error:', error);
                this.isConnected = false;
                this.updateConnectionStatus(false);
                this.handleReconnect();
            };
            
        } catch (error) {
            console.error('Failed to establish notification connection:', error);
            this.handleReconnect();
        }
    }
    
    handleNotificationData(data) {
        if (data.type === 'heartbeat') {
            // Just a keep-alive, update connection status
            this.updateConnectionStatus(true);
            return;
        }
        
        if (data.type === 'error') {
            console.error('Notification service error:', data.message);
            this.showToast('Notification service temporarily unavailable', 'error');
            return;
        }
        
        if (data.notifications && Array.isArray(data.notifications)) {
            data.notifications.forEach(notification => {
                this.processNotification(notification);
            });
        }
    }
    
    processNotification(notification) {
        // Add to notifications array
        this.notifications.unshift({
            ...notification,
            id: this.generateId(),
            read: false,
            receivedAt: new Date()
        });
        
        // Limit notifications array size
        if (this.notifications.length > 50) {
            this.notifications = this.notifications.slice(0, 50);
        }
        
        // Update UI elements
        if (this.options.enableBadges) {
            this.updateNotificationBadges(notification);
        }
        
        if (this.options.enableToasts) {
            this.showNotificationToast(notification);
        }
        
        if (this.options.enableSound) {
            this.playNotificationSound(notification.priority);
        }
        
        // Update page-specific elements
        this.updatePageElements(notification);
        
        // Trigger custom event for other components
        this.dispatchNotificationEvent(notification);
    }
    
    updateNotificationBadges(notification) {
        // Update various counters and badges
        const badges = {
            '.notification-count': () => this.getUnreadCount(),
            '.new-tickets-count': () => notification.type === 'new_tickets' ? notification.count : null,
            '.sla-breach-count': () => notification.type === 'sla_breach' ? notification.count : null,
            '.assignment-count': () => notification.type === 'new_assignments' ? notification.count : null
        };
        
        Object.entries(badges).forEach(([selector, countFn]) => {
            const elements = document.querySelectorAll(selector);
            const count = countFn();
            if (count !== null) {
                elements.forEach(el => {
                    el.textContent = count;
                    el.style.display = count > 0 ? 'inline' : 'none';
                });
            }
        });
    }
    
    showNotificationToast(notification) {
        const toast = this.createToastElement(notification);
        const container = document.getElementById('notification-container');
        
        if (container) {
            container.appendChild(toast);
            
            // Animate in
            setTimeout(() => toast.classList.add('show'), 100);
            
            // Auto-dismiss after 5 seconds for non-critical notifications
            if (notification.priority !== 'critical') {
                setTimeout(() => this.dismissToast(toast), 5000);
            }
        }
    }
    
    createToastElement(notification) {
        const toast = document.createElement('div');
        toast.className = `notification-toast priority-${notification.priority}`;
        toast.setAttribute('data-notification-id', notification.id || this.generateId());
        
        const iconMap = {
            'new_tickets': '🎫',
            'new_assignments': '👤',
            'sla_breach': '🚨',
            'sla_warning': '⚠️',
            'ticket_updates': '📬',
            'overdue_tickets': '⏰'
        };
        
        toast.innerHTML = `
            <div class="toast-icon">${iconMap[notification.type] || '📢'}</div>
            <div class="toast-content">
                <div class="toast-title">${this.escapeHtml(notification.title)}</div>
                <div class="toast-message">${this.escapeHtml(notification.message)}</div>
            </div>
            <div class="toast-actions">
                ${notification.url ? `<a href="${notification.url}" class="toast-action-link">View</a>` : ''}
                <button class="toast-dismiss" onclick="window.realtimeNotifications.dismissToast(this.closest('.notification-toast'))">&times;</button>
            </div>
        `;
        
        return toast;
    }
    
    dismissToast(toast) {
        toast.classList.remove('show');
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }
    
    playNotificationSound(priority) {
        if (!this.options.enableSound || !this.sounds) return;
        
        const soundMap = {
            'critical': 'alert',
            'warning': 'notification',
            'info': 'gentle'
        };
        
        const soundType = soundMap[priority] || 'gentle';
        
        // Use Web Audio API for simple beep sounds
        this.playBeep(soundType);
    }
    
    playBeep(type) {
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            // Different frequencies for different notification types
            const frequencies = {
                'alert': [800, 600], // Two-tone alert
                'notification': [440], // Single tone
                'gentle': [220] // Lower, gentler tone
            };
            
            const freq = frequencies[type] || [440];
            oscillator.frequency.setValueAtTime(freq[0], audioContext.currentTime);
            
            if (freq.length > 1) {
                oscillator.frequency.setValueAtTime(freq[1], audioContext.currentTime + 0.1);
            }
            
            gainNode.gain.setValueAtTime(0, audioContext.currentTime);
            gainNode.gain.linearRampToValueAtTime(0.3, audioContext.currentTime + 0.01);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.3);
            
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.3);
            
        } catch (error) {
            console.warn('Could not play notification sound:', error);
        }
    }
    
    updatePageElements(notification) {
        // Update stats bar if present
        if (notification.type === 'new_tickets' && document.querySelector('.stats-bar')) {
            this.refreshStatsBar();
        }
        
        // Flash update indicators
        const indicators = document.querySelectorAll('.update-indicator');
        indicators.forEach(indicator => {
            indicator.classList.add('flash');
            setTimeout(() => indicator.classList.remove('flash'), 1000);
        });
        
        // Update specific page elements based on current page
        const currentPage = window.location.pathname;
        
        if (currentPage.includes('tickets-simple.php')) {
            this.updateTicketsList(notification);
        } else if (currentPage.includes('index.php') || currentPage === '/ITSPtickets/') {
            this.updateDashboard(notification);
        }
    }
    
    updateTicketsList(notification) {
        // Add visual indicator that new data is available
        let refreshIndicator = document.querySelector('.refresh-available-indicator');
        
        if (!refreshIndicator && document.querySelector('.tickets-list')) {
            refreshIndicator = document.createElement('div');
            refreshIndicator.className = 'refresh-available-indicator';
            refreshIndicator.innerHTML = `
                <span>🔄 New updates available</span>
                <button onclick="location.reload()">Refresh</button>
                <button onclick="this.parentElement.style.display='none'">Dismiss</button>
            `;
            
            document.querySelector('.tickets-list').parentNode.insertBefore(
                refreshIndicator, 
                document.querySelector('.tickets-list')
            );
        }
    }
    
    updateDashboard(notification) {
        // Update dashboard widgets with new counts
        const widgets = document.querySelectorAll('[data-auto-update]');
        widgets.forEach(widget => {
            const updateUrl = widget.getAttribute('data-update-url');
            if (updateUrl) {
                this.fetchAndUpdateWidget(widget, updateUrl);
            }
        });
    }
    
    refreshStatsBar() {
        // Simple counter updates based on notifications
        const statElements = {
            '.stat': (el) => {
                if (el.textContent.includes('New:')) {
                    el.classList.add('updated');
                    setTimeout(() => el.classList.remove('updated'), 1000);
                }
            }
        };
        
        Object.entries(statElements).forEach(([selector, updateFn]) => {
            document.querySelectorAll(selector).forEach(updateFn);
        });
    }
    
    handleReconnect() {
        if (this.eventSource) {
            this.eventSource.close();
        }
        
        if (this.reconnectAttempts < this.options.maxReconnectAttempts) {
            this.reconnectAttempts++;
            console.log(`Attempting to reconnect (${this.reconnectAttempts}/${this.options.maxReconnectAttempts})...`);
            
            setTimeout(() => {
                this.connect();
            }, this.options.reconnectInterval);
        } else {
            console.error('Max reconnection attempts reached. Notifications disabled.');
            this.showToast('Real-time notifications are currently unavailable', 'error');
        }
    }
    
    updateConnectionStatus(connected) {
        const indicators = document.querySelectorAll('.connection-indicator');
        indicators.forEach(indicator => {
            indicator.className = `connection-indicator ${connected ? 'connected' : 'disconnected'}`;
            indicator.title = connected ? 'Real-time notifications active' : 'Connection lost';
        });
    }
    
    createNotificationContainer() {
        if (!document.getElementById('notification-container')) {
            const container = document.createElement('div');
            container.id = 'notification-container';
            container.className = 'notification-container';
            document.body.appendChild(container);
        }
    }
    
    loadSounds() {
        // Sounds will be generated using Web Audio API
        this.sounds = true; // Simple flag to indicate sound support
    }
    
    bindEvents() {
        // Handle page visibility changes
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && !this.isConnected) {
                this.connect();
            }
        });
        
        // Handle browser online/offline events
        window.addEventListener('online', () => {
            if (!this.isConnected) {
                this.connect();
            }
        });
        
        window.addEventListener('offline', () => {
            this.updateConnectionStatus(false);
        });
    }
    
    // Utility methods
    generateId() {
        return 'notification-' + Math.random().toString(36).substr(2, 9) + '-' + Date.now();
    }
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    getUnreadCount() {
        return this.notifications.filter(n => !n.read).length;
    }
    
    dispatchNotificationEvent(notification) {
        const event = new CustomEvent('realtime-notification', {
            detail: notification
        });
        window.dispatchEvent(event);
    }
    
    showToast(message, type = 'info') {
        const notification = {
            title: type === 'error' ? 'Error' : 'Information',
            message: message,
            priority: type,
            type: 'system'
        };
        this.showNotificationToast(notification);
    }
    
    // Public API methods
    disconnect() {
        if (this.eventSource) {
            this.eventSource.close();
            this.isConnected = false;
            this.updateConnectionStatus(false);
        }
    }
    
    markAsRead(notificationId) {
        const notification = this.notifications.find(n => n.id === notificationId);
        if (notification) {
            notification.read = true;
        }
    }
    
    clearAll() {
        this.notifications = [];
        const container = document.getElementById('notification-container');
        if (container) {
            container.innerHTML = '';
        }
    }
}

// Auto-initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Only initialize if not already done
    if (!window.realtimeNotifications) {
        window.realtimeNotifications = new RealTimeNotifications();
    }
});

// Export for manual initialization if needed
window.RealTimeNotifications = RealTimeNotifications;