describe("range tests -", function () {
    beforeEach(function () {
        this.initcalled = false;
        if ($(".calentim-test-input").length > 0) {
            $(".calentim-test-input").data("calentim").destroy();
            $(".calentim-test-input").remove();
        }
        this.input = $(
            "<input type='text' class='calentim-test-input' />"
        ).appendTo("body");
        var that = this;
        var disabled = {"2019-02-01":21,"2019-02-02":21,"2019-02-03":22,"2019-02-04":4,"2019-02-05":4,"2019-02-06":5,"2019-02-07":5,"2019-02-08":5,"2019-02-09":4,"2019-02-10":5,"2019-02-11":20,"2019-02-12":22,"2019-02-13":0,"2019-02-14":22,"2019-02-15":21,"2019-02-16":21,"2019-02-17":22,"2019-02-18":4,"2019-02-19":4,"2019-02-20":4,"2019-02-21":4,"2019-02-22":5,"2019-02-23":5,"2019-02-24":5,"2019-02-25":22,"2019-02-26":10,"2019-02-27":17,"2019-02-28":17,"2019-03-01":22,"2019-03-02":22,"2019-03-03":22,"2019-03-04":5,"2019-03-05":5,"2019-03-06":5,"2019-03-07":5,"2019-03-08":5,"2019-03-09":5,"2019-03-10":5,"2019-03-11":22,"2019-03-12":22,"2019-03-13":0,"2019-03-14":0,"2019-03-15":0,"2019-03-16":22,"2019-03-17":20,"2019-03-18":4,"2019-03-19":4,"2019-03-20":4,"2019-03-21":4,"2019-03-22":4,"2019-03-23":4,"2019-03-24":0,"2019-03-25":20,"2019-03-26":18,"2019-03-27":0,"2019-03-28":0,"2019-03-29":20,"2019-03-30":20,"2019-03-31":20,"2019-04-01":20};
        this.input.calentim({
            oninit: function (instance) {
                instance.setDisplayDate(moment("01-02-2019","DD-MM-YYYY"));
                that.initcalled = true;
            },
            disabledRanges: [
                {
                    start: moment("07-02-2019", "DD-MM-YYYY"), // single day unavailable
                    end: moment("07-02-2019", "DD-MM-YYYY")
                },
                {
                    start: moment("14-02-2019", "DD-MM-YYYY"),
                    end: moment("15-02-2019", "DD-MM-YYYY")
                },
                {
                    start: moment("21-02-2019", "DD-MM-YYYY"),
                    end: moment("23-02-2019", "DD-MM-YYYY")
                },
            ],
            disableDays: function(day){
                return !(day.format('YYYY-MM-DD') in disabled) || disabled[day.format('YYYY-MM-DD')] == 0 ? true : false;
            },
            startEmpty: true,
            continuous: true,
            inline: true
        });
        this.calentim = this.input.data("calentim");
        this.element = this.calentim.input;
    });
    afterEach(function () {
        this.input = $(".calentim-test-input");
        this.input.data("calentim").destroy();
        this.calentim = null;
        this.input.remove();
    });

    // disableonlystart tests
    // disabled ranges
    it("should select any date as end date", function () {
        this.calentim = this.input.data("calentim");
        this.calentim.config.disableOnlyStart = true;
        expect(this.calentim.input.is(":visible")).toBe(true);
        expect(this.calentim.calendars.find("[data-value='" + moment("01-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(false);
        expect(this.calentim.calendars.find("[data-value='" + moment("07-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
        this.calentim.calendars.find("[data-value='" + moment("01-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").first().click();
        expect(this.calentim.calendars.find("[data-value='" + moment("01-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(false);
        expect(this.calentim.calendars.find("[data-value='" + moment("07-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(false);
        this.calentim.calendars.find("[data-value='" + moment("07-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").first().click();
        expect(this.calentim.config.startDate.middleOfDay().inspect()).toBe(moment("01-02-2019", "DD-MM-YYYY").middleOfDay().inspect());
        expect(this.calentim.config.endDate.middleOfDay().inspect()).toBe(moment("07-02-2019", "DD-MM-YYYY").middleOfDay().inspect());
    });

    it("shouldn't select any date as start date", function () {
        this.calentim = this.input.data("calentim");
        this.calentim.config.disableOnlyStart = true;
        expect(this.calentim.input.is(":visible")).toBe(true);
        expect(this.calentim.calendars.find("[data-value='" + moment("07-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
        this.calentim.calendars.find("[data-value='" + moment("07-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").first().click();
        expect(this.calentim.calendars.find("[data-value='" + moment("07-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
        this.calentim.calendars.find("[data-value='" + moment("07-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").first().click();
        expect(this.calentim.config.startDate).toBe(null);
        expect(this.calentim.config.endDate).toBe(null);
    });

    // disableonlystart tests
    // disabled ranges
    it("should select any date as end date #2", function () {
        this.calentim = this.input.data("calentim");
        this.calentim.config.disableOnlyStart = true;
        expect(this.calentim.input.is(":visible")).toBe(true);
        expect(this.calentim.calendars.find("[data-value='" + moment("12-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(false);
        expect(this.calentim.calendars.find("[data-value='" + moment("14-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
        this.calentim.calendars.find("[data-value='" + moment("12-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").first().click();
        expect(this.calentim.calendars.find("[data-value='" + moment("12-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(false);
        expect(this.calentim.calendars.find("[data-value='" + moment("14-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(false);
        this.calentim.calendars.find("[data-value='" + moment("14-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").first().click();
        expect(this.calentim.config.startDate.middleOfDay().inspect()).toBe(moment("12-02-2019", "DD-MM-YYYY").middleOfDay().inspect());
        expect(this.calentim.config.endDate.middleOfDay().inspect()).toBe(moment("14-02-2019", "DD-MM-YYYY").middleOfDay().inspect());
    });

    it("shouldn't select any date as start date #2", function () {
        this.calentim = this.input.data("calentim");
        this.calentim.config.disableOnlyStart = true;
        expect(this.calentim.input.is(":visible")).toBe(true);
        expect(this.calentim.calendars.find("[data-value='" + moment("13-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
        this.calentim.calendars.find("[data-value='" + moment("13-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").first().click();
        expect(this.calentim.calendars.find("[data-value='" + moment("13-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
        this.calentim.calendars.find("[data-value='" + moment("13-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").first().click();
        expect(this.calentim.config.startDate).toBe(null);
        expect(this.calentim.config.endDate).toBe(null);
    });

    // disableonlystart tests
    // disabled ranges
    it("should select any date as end date #3", function () {
        this.calentim = this.input.data("calentim");
        this.calentim.config.disableOnlyStart = true;
        expect(this.calentim.input.is(":visible")).toBe(true);
        expect(this.calentim.calendars.find("[data-value='" + moment("12-03-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(false);
        expect(this.calentim.calendars.find("[data-value='" + moment("14-03-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
        this.calentim.calendars.find("[data-value='" + moment("12-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").first().click();
        expect(this.calentim.calendars.find("[data-value='" + moment("12-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(false);
        expect(this.calentim.calendars.find("[data-value='" + moment("14-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(false);
        this.calentim.calendars.find("[data-value='" + moment("14-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").first().click();
        expect(this.calentim.config.startDate.middleOfDay().inspect()).toBe(moment("12-02-2019", "DD-MM-YYYY").middleOfDay().inspect());
        expect(this.calentim.config.endDate.middleOfDay().inspect()).toBe(moment("14-02-2019", "DD-MM-YYYY").middleOfDay().inspect());
    });

    it("shouldn't select any date as start date #3", function () {
        this.calentim = this.input.data("calentim");
        this.calentim.config.disableOnlyStart = true;
        expect(this.calentim.input.is(":visible")).toBe(true);
        expect(this.calentim.calendars.find("[data-value='" + moment("13-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
        this.calentim.calendars.find("[data-value='" + moment("13-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").first().click();
        expect(this.calentim.calendars.find("[data-value='" + moment("13-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
        this.calentim.calendars.find("[data-value='" + moment("13-02-2019", "DD-MM-YYYY").middleOfDay().unix() + "']").first().click();
        expect(this.calentim.config.startDate).toBe(null);
        expect(this.calentim.config.endDate).toBe(null);
    });

});