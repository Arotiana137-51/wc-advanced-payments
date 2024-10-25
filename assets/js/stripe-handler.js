// Initialize Stripe Elements and handle payment submission
(function($) {
    'use strict';

    // Store DOM elements
    const elements = {
        form: $('#payment-form'),
        cardElement: null,
        errorDiv: $('#card-errors'),
        submitButton: $('#submit-payment')
    };

    // Store Stripe elements
    let stripe = null;
    let element = null;
    let card = null;

    /**
     * Initialize Stripe
     */
    function initStripe() {
        // Initialize Stripe with publishable key
        stripe = Stripe(wc_stripe_custom_params.publishable_key);
        
        // Create Elements instance
        elements = stripe.elements();

        // Create and mount the Card Element
        card = elements.create('card', {
            style: {
                base: {
                    color: '#32325d',
                    fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
                    fontSmoothing: 'antialiased',
                    fontSize: '16px',
                    '::placeholder': {
                        color: '#aab7c4'
                    }
                },
                invalid: {
                    color: '#fa755a',
                    iconColor: '#fa755a'
                }
            }
        });

        // Mount the card element
        card.mount('#card-element');

        // Handle real-time validation errors
        card.addEventListener('change', handleCardChange);
    }

    /**
     * Handle card input changes
     */
    function handleCardChange(event) {
        if (event.error) {
            showError(event.error.message);
        } else {
            clearError();
        }
    }

    /**
     * Display error message
     */
    function showError(message) {
        elements.errorDiv
            .html(`<div class="alert alert-danger">${message}</div>`)
            .show();
    }

    /**
     * Clear error message
     */
    function clearError() {
        elements.errorDiv
            .html('')
            .hide();
    }

    /**
     * Handle form submission
     */
    async function handleSubmit(event) {
        event.preventDefault();

        // Disable the submit button to prevent double submission
        elements.submitButton.prop('disabled', true);
        
        try {
            // Create payment method
            const result = await stripe.createPaymentMethod({
                type: 'card',
                card: card,
                billing_details: {
                    name: $('#billing_first_name').val() + ' ' + $('#billing_last_name').val(),
                    email: $('#billing_email').val(),
                    phone: $('#billing_phone').val(),
                    address: {
                        line1: $('#billing_address_1').val(),
                        line2: $('#billing_address_2').val(),
                        city: $('#billing_city').val(),
                        state: $('#billing_state').val(),
                        postal_code: $('#billing_postcode').val(),
                        country: $('#billing_country').val()
                    }
                }
            });

            if (result.error) {
                throw result.error;
            }

            // Add payment method ID to form
            $('<input>')
                .attr({
                    type: 'hidden',
                    name: 'stripe_payment_method',
                    value: result.paymentMethod.id
                })
                .appendTo(elements.form);

            // Submit the form
            elements.form.get(0).submit();

        } catch (error) {
            showError(error.message);
            elements.submitButton.prop('disabled', false);
        }
    }

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Only initialize on checkout page when Stripe is the selected payment method
        if ($('#payment_method_stripe_custom').length && $('#payment_method_stripe_custom').is(':checked')) {
            initStripe();
        }

        // Initialize when payment method changes to Stripe
        $('form.checkout').on('change', 'input[name="payment_method"]', function() {
            if ($(this).val() === 'stripe_custom') {
                initStripe();
            }
        });

        // Handle form submission
        elements.form.on('submit', handleSubmit);
    });

    /**
     * Handle AJAX checkout success
     */
    $(document.body).on('checkout_error', function() {
        elements.submitButton.prop('disabled', false);
    });

    /**
     * Handle payment method selection on checkout page
     */
    $(document.body).on('payment_method_selected', function() {
        if ($('#payment_method_stripe_custom').is(':checked')) {
            elements.form.show();
        } else {
            elements.form.hide();
        }
    });

    /**
     * Handle order review submission
     */
    $(document.body).on('checkout_place_order_stripe_custom', function() {
        return elements.form.triggerHandler('submit');
    });

    /**
     * Update card element styles on theme color scheme change
     */
    function updateCardElementStyles() {
        if (card) {
            card.update({
                style: {
                    base: {
                        color: window.getComputedStyle(document.body).color,
                        fontFamily: window.getComputedStyle(document.body).fontFamily
                    }
                }
            });
        }
    }

    // Listen for theme color scheme changes
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', updateCardElementStyles);
    }

})(jQuery);