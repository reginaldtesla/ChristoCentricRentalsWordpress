
<div class="rental-wishlist-box-warpper">

    <div id="rntp-wish-errors">
        <ul></ul>
    </div>

    <div id="rntp-wish-success"></div>

    <div class="rental-wishlist-box">
        <div class="rental-inner-title">
            <h3>Wishlist</h3>
        </div>
        <div class="rental-wishlist-form-row">

            <div class="rental-wishlist-form-col">
                <div class="rntp-wishlist-field">
                    <div class="rntp-input-text">
                        <input type="text" id="rntp-wish-name"
                            name="rntp-wish-name"
                            value=""
                            placeholder="Name*"
                            required
                            />
                    </div>
                </div>
            </div> <!--.rental-wishlist-form-col -->

            <div class="rental-wishlist-form-col">
                <div class="rntp-wishlist-field">
                    <div class="rntp-input-text">
                        <input type="email" id="rntp-wish-email"
                            name="rntp-wish-email"
                            value=""
                            placeholder="Email*"
                            required
                            />
                    </div>
                </div>
            </div> <!--.rental-wishlist-form-col -->

            <div class="rental-wishlist-form-col">
                <div class="rntp-wishlist-field">
                    <div class="rntp-input-text">
                        <input type="text"
                            class="date-form rntp-date"
                            name="rntp-wish-date"
                            id="rntp-wish-date"
                            required="required"
                            placeholder="Date*"
                        />
                    </div>
                </div>
            </div> <!--.rental-wishlist-form-col -->

            <div class="rental-wishlist-form-col">
                <div class="rntp-wishlist-field">
                    <div class="rntp-input-text">
                        <input type="text" id="rntp-wish-location"
                            name="rntp-wish-location"
                            value=""
                            placeholder="Address*"
                            required
                            />
                    </div>
                </div>
            </div> <!--.rental-wishlist-form-col -->

            <div class="rental-wishlist-form-col">
                <div class="rntp-wishlist-field">
                    <div class="rntp-input-text">
                        <input type="text" id="rntp-wish-address-2"
                            name="rntp-wish-address-2"
                            value=""
                            placeholder="Address Line 2"
                            />
                    </div>
                </div>
            </div> <!--.rental-wishlist-form-col -->
            

            <div class="rental-wishlist-form-row rental-flex-direction-row">

                <div class="rental-wishlist-form-col">
                    <div class="rntp-wishlist-field">
                        <div class="rntp-input-text">
                            <input type="text" id="rntp-wish-zip"
                                name="rntp-wish-zip"
                                value=""
                                placeholder="Zip"
                                />
                        </div>
                    </div>
                </div> <!--.rental-wishlist-form-col -->

                <div class="rental-wishlist-form-col">
                    <div class="rntp-wishlist-field">
                        <div class="rntp-input-text">
                            <input type="text" id="rntp-wish-city"
                                name="rntp-wish-city"
                                value=""
                                placeholder="City"
                                />
                        </div>
                    </div>
                </div> <!--.rental-wishlist-form-col -->

                <div class="rental-wishlist-form-col">
                    <div class="rntp-wishlist-field">
                        <div class="rntp-input-text">
                            <input type="text" id="rntp-wish-state"
                                name="rntp-wish-state"
                                value=""
                                placeholder="State"
                                />
                        </div>
                    </div>
                </div> <!--.rental-wishlist-form-col -->

            </div> <!-- .rental-wishlist-form-row -->

            <div class="rental-wishlist-form-col rental-hidden">
                <div class="rntp-wishlist-field">
                    <div class="rntp-input-text">
                        <input type="hidden" id="rntp-wish-country"
                            name="rntp-wish-country"
                            value=""
                            placeholder="country"
                            />
                    </div>
                </div>
            </div> <!--.rental-wishlist-form-col -->


            <div class="rental-wishlist-form-col">
                <div class="rntp-wishlist-field">
                    <div class="rntp-input-text">
                        <input type="tel" id="rntp-wish-phone"
                            name="rntp-wish-phone"
                            value=""
                            placeholder="Phone Number"
                            />
                    </div>
                </div>
            </div> <!--.rental-wishlist-form-col -->

            <div class="rental-wishlist-form-col">
                <div class="rntp-wishlist-field">
                    <div class="rntp-input-text">
                        <input type="number" id="rntp-wish-guest"
                            name="rntp-wish-guest"
                            value=""
                            placeholder="Guest Count"
                            min="1" step="1"
                            />
                    </div>
                </div>
            </div> <!--.rental-wishlist-form-col -->

        </div> <!-- .rental-wishlist-form-row --> 



        <div class="rental-wishlist-form-row">
            <div class="rental-wishlist-form-col">
                <div class="rntp-wishlist-field rental-w-100">
                    <div class="rntp-input-text">
                        <textarea rows="4" 
                            id="rntp-wish-note"
                            name="rntp-wish-note"
                            placeholder="Notes"></textarea>
                    </div>
                </div>
            </div>
        </div> <!--.rental-wishlist-form-row -->


        <div class="rental-wishlist-form-row">
            <button id="rntp-wish-submit" name="rntp-wish-submit" class="btn btn-success">
                <span class="dashicons dashicons-yes"></span><?php _e('Submit', 'rentopian-sync') ?>
            </button>
        </div> <!--.rental-wishlist-form-row -->
        
    </div>
</div>