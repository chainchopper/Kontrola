(function($) {
    const KontrolaOnboarding = {
        currentStep: 1,
        totalSteps: 5,
        config: {},

        init() {
            this.setupEventListeners();
            this.loadSavedConfig();
            this.showStep(this.currentStep);
        },

        setupEventListeners() {
            // Step navigation
            $(document).on('click', '.step-button[data-step]', (e) => {
                e.preventDefault();
                const step = parseInt($(e.target).data('step'));
                this.showStep(step);
            });

            // Next/Previous buttons
            $(document).on('click', '.btn-next', () => {
                if (this.validateStep(this.currentStep)) {
                    this.saveStepData();
                    if (this.currentStep < this.totalSteps) {
                        this.showStep(this.currentStep + 1);
                    }
                }
            });

            $(document).on('click', '.btn-previous', () => {
                if (this.currentStep > 1) {
                    this.showStep(this.currentStep - 1);
                }
            });

            // Test connection button
            $(document).on('click', '.btn-test-connection', (e) => {
                e.preventDefault();
                this.testConnection();
            });

            // Complete setup button
            $(document).on('click', '.btn-complete-setup', (e) => {
                e.preventDefault();
                this.completeSetup();
            });

            // Radio button selection for vector DB backend
            $(document).on('change', 'input[name="vector_backend"]', () => {
                this.updateBackendDetails();
            });

            // Checkbox for cache
            $(document).on('change', '#cache_enabled', () => {
                $('#cache-config').toggle();
            });

            // Checkbox for object storage
            $(document).on('change', '#object_storage', () => {
                $('#object-storage-config').toggle();
            });
        },

        showStep(step) {
            if (step < 1 || step > this.totalSteps) return;

            this.currentStep = step;

            // Hide all steps
            $('.wizard-step').hide();

            // Show current step
            $(`[data-step="${step}"]`).show();

            // Update progress
            this.updateProgress();

            // Update button states
            this.updateButtons();

            // Load step-specific content
            this.loadStepContent(step);
        },

        updateProgress() {
            const progress = (this.currentStep / this.totalSteps) * 100;
            $('.progress-bar').css('width', progress + '%');
            $('.progress-text').text(`Step ${this.currentStep} of ${this.totalSteps}`);

            // Update step buttons
            $('.step-button').removeClass('active');
            $(`.step-button[data-step="${this.currentStep}"]`).addClass('active');
        },

        updateButtons() {
            const showPrev = this.currentStep > 1;
            const showNext = this.currentStep < this.totalSteps;

            $('.btn-previous').toggle(showPrev);
            $('.btn-next').toggle(showNext);

            if (this.currentStep === this.totalSteps) {
                $('.btn-complete-setup').show();
            } else {
                $('.btn-complete-setup').hide();
            }
        },

        loadStepContent(step) {
            switch (step) {
                case 1:
                    // Welcome step - no action needed
                    break;
                case 2:
                    this.loadVectorBackendOptions();
                    break;
                case 3:
                    this.loadCacheOptions();
                    break;
                case 4:
                    this.loadObjectStorageOptions();
                    break;
                case 5:
                    this.displaySummary();
                    break;
            }
        },

        validateStep(step) {
            switch (step) {
                case 2:
                    return $('input[name="vector_backend"]:checked').length > 0;
                case 3:
                    return true; // Cache is optional
                case 4:
                    return true; // Object storage is optional
                default:
                    return true;
            }
        },

        saveStepData() {
            switch (this.currentStep) {
                case 2:
                    this.config.vector_db = $('input[name="vector_backend"]:checked').val();
                    break;
                case 3:
                    this.config.cache_enabled = $('#cache_enabled').is(':checked');
                    break;
                case 4:
                    this.config.object_storage = $('#object_storage').is(':checked');
                    break;
            }
        },

        loadVectorBackendOptions() {
            // Options are already in the form
            this.updateBackendDetails();
        },

        updateBackendDetails() {
            const backend = $('input[name="vector_backend"]:checked').val();
            $('.backend-details').hide();
            $(`[data-backend="${backend}"]`).show();
        },

        loadCacheOptions() {
            if ($('#cache_enabled').is(':checked')) {
                $('#cache-config').show();
            } else {
                $('#cache-config').hide();
            }
        },

        loadObjectStorageOptions() {
            if ($('#object_storage').is(':checked')) {
                $('#object-storage-config').show();
            } else {
                $('#object-storage-config').hide();
            }
        },

        testConnection() {
            const backend = $('input[name="vector_backend"]:checked').val();

            $.ajax({
                url: kontrolaOnboarding.restUrl + 'test-connection',
                method: 'POST',
                headers: {
                    'X-WP-Nonce': kontrolaOnboarding.nonce,
                },
                data: JSON.stringify({
                    vector_db: backend,
                    cache_enabled: $('#cache_enabled').is(':checked'),
                    object_storage: $('#object_storage').is(':checked'),
                }),
                contentType: 'application/json',
                success: (response) => {
                    if (response.ok) {
                        this.showNotice('Connection test passed!', 'success');
                    } else {
                        this.showNotice(`Connection test failed: ${response.message || 'Unknown error'}`, 'error');
                    }
                },
                error: (xhr) => {
                    this.showNotice('Connection test failed - server error', 'error');
                },
            });
        },

        displaySummary() {
            const backend = this.config.vector_db || 'lancedb';
            const cacheEnabled = this.config.cache_enabled ? 'Yes' : 'No';
            const storageEnabled = this.config.object_storage ? 'Yes' : 'No';

            const summary = `
                <h3>Setup Summary</h3>
                <p><strong>Vector Database:</strong> ${this.escapeHtml(backend)}</p>
                <p><strong>Cache (Redis):</strong> ${cacheEnabled}</p>
                <p><strong>Object Storage (MinIO):</strong> ${storageEnabled}</p>
                <p>Click "Complete Setup" to finalize your configuration.</p>
            `;

            $('#summary-content').html(summary);
        },

        completeSetup() {
            this.saveStepData();

            $.ajax({
                url: kontrolaOnboarding.restUrl + 'save',
                method: 'POST',
                headers: {
                    'X-WP-Nonce': kontrolaOnboarding.nonce,
                },
                data: JSON.stringify(this.config),
                contentType: 'application/json',
                success: (response) => {
                    if (response.ok) {
                        this.showNotice('Setup completed successfully!', 'success');
                        setTimeout(() => {
                            window.location.href = window.location.href.split('?')[0] + '?page=kontrola-services';
                        }, 2000);
                    } else {
                        this.showNotice(`Setup failed: ${response.message || 'Unknown error'}`, 'error');
                    }
                },
                error: (xhr) => {
                    this.showNotice('Setup failed - server error', 'error');
                },
            });
        },

        loadSavedConfig() {
            // Try to load previously saved config from localStorage or server
            const saved = localStorage.getItem('kontrola_onboarding_config');
            if (saved) {
                this.config = JSON.parse(saved);
            }
        },

        showNotice(message, type) {
            const notice = $(`
                <div class="notice notice-${type} is-dismissible">
                    <p>${this.escapeHtml(message)}</p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">Dismiss this notice.</span>
                    </button>
                </div>
            `);

            $('.wizard-container').prepend(notice);

            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                notice.fadeOut(() => notice.remove());
            }, 5000);
        },

        escapeHtml(text) {
            const div = $('<div>').text(text);
            return div.html();
        },
    };

    // Initialize when document is ready
    $(document).ready(() => {
        KontrolaOnboarding.init();
    });

    // Also expose to window for debugging
    window.KontrolaOnboarding = KontrolaOnboarding;
})(jQuery);
