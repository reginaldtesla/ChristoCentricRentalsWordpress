describe("initial testing", function () {
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
		this.input.calentim({
			oninit: function () {
				that.initcalled = true;
			}
		});
		this.calentim = this.input.data("calentim");
		this.calendars = this.calentim.container.find(".calentim-calendars").first();
		this.element = this.calentim.input;
		this.moment = moment(); // initialization time
	});

	afterEach(function () {
		this.input = $(".calentim-test-input");
		this.input.data("calentim").destroy();
		this.calentim = null;
		this.input.remove();
	});

	it("should have appended an this.input to the body", function () {
		expect($(".calentim-test-input").length).toEqual(1);
	});

	it("should have given an this.calentim object", function () {
		expect(this.calentim).toEqual(jasmine.any(Object));
	});

	it("should have oninit event called", function () {
		expect(this.initcalled).toBe(true);
	});

	it("should have initcompleted flag set", function () {
		expect(this.calentim.globals.initComplete).toBe(true);
	});

	it("should have the date set on the this.input", function () {
		expect(this.input.val()).toEqual(this.calentim.config.startDate.format(this.calentim.config.format) + this.calentim.config.dateSeparator + this.calentim.config.endDate.format(this.calentim.config.format));
	});

	it("should have the dropdown hidden", function () {
		expect($(".calentim-input").is(":visible")).toBe(false);
	});

	it("should have the dropdown visible", function () {
		this.input.click();
		expect($(".calentim-input").is(":visible")).toBe(true);
	});

	it("should have the custom date set on the this.input", function () {
		var startDate = moment({ year: 2016, month: 2, day: 13, hour: 14, minute: 37 });
		var endDate = moment({ year: 2016, month: 4, day: 24, hour: 11, minute: 35 });
		this.input.val(startDate.format(this.calentim.config.format) + this.calentim.config.dateSeparator + endDate.format(this.calentim.config.format));
		this.calentim.fetchInputs();
		expect(this.input.val()).toEqual(startDate.format(this.calentim.config.format) + this.calentim.config.dateSeparator + endDate.format(this.calentim.config.format));
		expect(this.calentim.config.startDate.format("L LT")).toEqual(startDate.format("L LT"));
		expect(this.calentim.config.endDate.format("L LT")).toEqual(endDate.format("L LT"));
	});

	it("should have the correct months visible and days selected on the calendar", function () {
		expect(this.element.find(".calentim-calendar").length).toEqual(this.calentim.config.calendarCount);
		expect(this.element.find(".calentim-calendar:first").data("month")).toEqual(this.calentim.config.startDate.month());
		expect(this.element.find(".calentim-calendar:last").data("month")).toEqual(this.calentim.config.startDate.clone().add(this.calentim.config.calendarCount - 1, "months").month());
		expect(this.element.find(".calentim-day[data-value='" + this.calentim.config.startDate.clone().middleOfDay().unix() + "']").hasClass("calentim-start")).toBe(true);
		expect(this.element.find(".calentim-day[data-value='" + this.calentim.config.endDate.clone().middleOfDay().unix() + "']").hasClass("calentim-end")).toBe(true);
		expect(this.element.find(".calentim-day[data-value='" + this.calentim.config.endDate.clone().middleOfDay().unix() + "']").hasClass("calentim-selected")).toBe(true);
		expect(this.element.find(".calentim-day[data-value='" + this.calentim.config.endDate.clone().middleOfDay().unix() + "']").hasClass("calentim-selected")).toBe(true);
		var days = this.element.find(".calentim-day");
        for (var d = 0; d < days.length; d++) {
            var day = days.eq(d);
            var mday = moment.unix(day.data("value"));
            var month = day.parents(".calentim-calendar").first().data("month");
            var daystr = day.text();
            var year = day.parents(".calentim-calendar").first().find(".calentim-year-switch").text();
            expect(mday.hours()).toEqual(12);
            expect(mday.minutes()).toEqual(0);
            expect(mday.seconds()).toEqual(0);
            if (day.hasClass("calentim-not-in-month")) {
                expect((mday.month()).toString()).not.toEqual(month.toString());
            } else {
                expect((mday.month()).toString()).toEqual(month.toString());
            }
            expect(mday.date().toString()).toEqual(daystr);
            if (mday.month().toString() === month.toString())
                expect(mday.year().toString()).toEqual(year);
        }
	});

	it("should have the correct time visible on the calendar", function () {
		var startDate = moment({ year: 2016, month: 2, day: 13, hour: 14, minute: 37 });
		var endDate = moment({ year: 2016, month: 4, day: 24, hour: 11, minute: 35 });
		this.input.val(startDate.format(this.calentim.config.format) + this.calentim.config.dateSeparator + endDate.format(this.calentim.config.format));
		this.calentim.fetchInputs();
		if (this.calentim.config.hourFormat !== 12) {
			expect(this.element.find(".calentim-timepicker-start .calentim-timepicker-hours .calentim-hour-selected").text()).toEqual(startDate.hours());
			expect(this.element.find(".calentim-timepicker-start .calentim-timepicker-minutes .calentim-minute-selected").text()).toEqual(startDate.minutes());
			expect(this.element.find(".calentim-timepicker-end .calentim-timepicker-hours .calentim-hour-selected").text()).toEqual(endDate.hours());
			expect(this.element.find(".calentim-timepicker-end .calentim-timepicker-minutes .calentim-minute-selected").text()).toEqual(endDate.minutes());
		} else {
			var starts = startDate.format("hh mm a").split(" ");
			var ends = endDate.format("hh mm a").split(" ");
			expect(this.element.find(".calentim-timepicker-start .calentim-timepicker-hours .calentim-hour-selected").text()).toEqual(starts[0]);
			expect(this.element.find(".calentim-timepicker-start .calentim-timepicker-minutes .calentim-minute-selected").text()).toEqual(starts[1]);
			expect(this.element.find(".calentim-timepicker-start .calentim-ampm-selected").text().toLowerCase()).toEqual(starts[2]);
			expect(this.element.find(".calentim-timepicker-end .calentim-timepicker-hours .calentim-hour-selected").text()).toEqual(ends[0]);
			expect(this.element.find(".calentim-timepicker-end .calentim-timepicker-minutes .calentim-minute-selected").text()).toEqual(ends[1]);
			expect(this.element.find(".calentim-timepicker-end .calentim-ampm-selected").text().toLowerCase()).toEqual(ends[2]);
		}
	});

	it("should move to the next month with arrow click", function () {
		jasmine.clock().install();
		this.input.click();
		for (var i = 0; i < 12; i++) {
			var deferred = new $.Deferred;
			var currentMonth = this.element.find(".calentim-calendar:first").data("month");
			this.element.find(".calentim-next").click();
			jasmine.clock().tick(101);
			var modifiedMonth = this.element.find(".calentim-calendar:first").data("month");
			expect(modifiedMonth === (currentMonth == 11 ? 0 : currentMonth + 1)).toBe(true);
		}
		jasmine.clock().uninstall();
	});

	it("should move to the previous month with arrow click", function () {
		jasmine.clock().install();
		this.input.click();
		for (var i = 0; i < 12; i++) {
			var deferred = new $.Deferred;
			var currentMonth = this.element.find(".calentim-calendar:first").data("month");
			this.element.find(".calentim-prev").click();
			jasmine.clock().tick(101);
			var modifiedMonth = this.element.find(".calentim-calendar:first").data("month");
			expect(modifiedMonth === (currentMonth == 0 ? 11 : currentMonth - 1)).toBe(true);
		}
		jasmine.clock().uninstall();
	});

	it("should increase the start hour", function () {
		// set end date to next day to prevent swapping
		this.input.click();
		this.calentim.config.endDate.add(2, "day");
		for (var i = 0; i < 25; i++) {
			// effect
			var oldSelected = parseInt($(".calentim-timepicker-start .calentim-hour-selected").text(), 10);
			$(".calentim-timepicker-start .calentim-timepicker-hours-down").trigger("click");
			var newSelected = parseInt($(".calentim-timepicker-start .calentim-hour-selected").text(), 10);
			// tests
			if (this.calentim.config.hourFormat == 12 && oldSelected === 12) expect(newSelected).toEqual(1);
			else if (this.calentim.config.hourFormat != 12 && oldSelected === 23) expect(newSelected).toEqual(0);
			else expect(oldSelected + 1).toEqual(newSelected);
			// should switch the start and end hours
			if (this.calentim.config.hourFormat == 12) expect(newSelected).toEqual(parseInt(this.calentim.config.startDate.clone().format("h"), 10));
			else expect(newSelected).toEqual(parseInt(this.calentim.config.startDate.clone().format("H"), 10));
		}
	});
	it("should increase the end hour", function () {
		// set end date to next day to prevent swapping
		this.input.click();
		this.calentim.config.endDate.add(2, "day");
		for (var i = 0; i < 25; i++) {
			// effect
			var oldSelected = parseInt($(".calentim-timepicker-end .calentim-hour-selected").text(), 10);
			$(".calentim-timepicker-end .calentim-timepicker-hours-down").trigger("click");
			var newSelected = parseInt($(".calentim-timepicker-end .calentim-hour-selected").text(), 10);
			// tests
			if (this.calentim.config.hourFormat === 12 && oldSelected === 12) expect(newSelected).toEqual(1);
			else if (this.calentim.config.hourFormat !== 12 && oldSelected === 23) expect(newSelected).toEqual(0);
			else expect(oldSelected + 1).toEqual(newSelected);
			if (this.calentim.config.hourFormat == 12) expect(newSelected).toEqual(parseInt(this.calentim.config.endDate.clone().format("h"), 10));
			else expect(newSelected).toEqual(parseInt(this.calentim.config.endDate.clone().format("H"), 10));
		}
	});
	it("should decrease the start hour", function () {
		// set end date to next day to prevent swapping
		this.input.click();
		this.calentim.config.endDate.add(2, "day");
		for (var i = 0; i < 25; i++) {
			// effect
			var oldSelected = parseInt($(".calentim-timepicker-start .calentim-hour-selected").text(), 10);
			$(".calentim-timepicker-start .calentim-timepicker-hours-up").trigger("click");
			var newSelected = parseInt($(".calentim-timepicker-start .calentim-hour-selected").text(), 10);
			// tests
			if (this.calentim.config.hourFormat == 12 && oldSelected === 1) expect(newSelected).toEqual(12);
			else if (this.calentim.config.hourFormat != 12 && oldSelected === 0) expect(newSelected).toEqual(23);
			else expect(oldSelected - 1).toEqual(newSelected);
			if (this.calentim.config.hourFormat == 12) expect(newSelected).toEqual(parseInt(this.calentim.config.startDate.clone().format("h"), 10));
			else expect(newSelected).toEqual(parseInt(this.calentim.config.startDate.clone().format("H"), 10));
		}
	});
	it("should decrease the end hour", function () {
		// set end date to next day to prevent swapping
		this.input.click();
		this.calentim.config.endDate.add(2, "day");
		// effect
		for (var i = 0; i < 25; i++) {
			var oldSelected = parseInt($(".calentim-timepicker-end .calentim-hour-selected").text(), 10);
			$(".calentim-timepicker-end .calentim-timepicker-hours-up").trigger("click");
			var newSelected = parseInt($(".calentim-timepicker-end .calentim-hour-selected").text(), 10);
			// tests
			if (this.calentim.config.hourFormat == 12 && oldSelected === 1) expect(newSelected).toEqual(12);
			else if (this.calentim.config.hourFormat != 12 && oldSelected === 0) expect(newSelected).toEqual(23);
			else expect(oldSelected - 1).toEqual(newSelected);
			// should switch the start and end hours
			if (this.calentim.config.hourFormat == 12) expect(newSelected).toEqual(parseInt(this.calentim.config.endDate.clone().format("h"), 10));
			else expect(newSelected).toEqual(parseInt(this.calentim.config.endDate.clone().format("H"), 10));
		}
	});
	it("should increase the start minute", function () {
		// effect
		var oldSelected = parseInt($(".calentim-timepicker-start .calentim-minute-selected").text(), 10);
		$(".calentim-timepicker-start .calentim-timepicker-minutes-down").trigger("click");
		var newSelected = parseInt($(".calentim-timepicker-start .calentim-minute-selected").text(), 10);
		// tests
		expect((oldSelected + 1) % 60).toEqual(newSelected);
	});
	it("should increase the end minute", function () {
		// effect
		var oldSelected = parseInt($(".calentim-timepicker-end .calentim-minute-selected").text(), 10);
		$(".calentim-timepicker-end .calentim-timepicker-minutes-down").trigger("click");
		var newSelected = parseInt($(".calentim-timepicker-end .calentim-minute-selected").text(), 10);
		// tests
		expect((oldSelected + 1) % 60).toEqual(newSelected);
	});
	it("should decrease the start minute", function () {
		// effect
		var oldSelected = parseInt($(".calentim-timepicker-start .calentim-minute-selected").text(), 10);
		$(".calentim-timepicker-start .calentim-timepicker-minutes-up").trigger("click");
		var newSelected = parseInt($(".calentim-timepicker-start .calentim-minute-selected").text(), 10);
		// tests
		expect((oldSelected + 59) % 60).toEqual(newSelected);
	});
	it("should decrease the end minute", function () {
		// effect
		var oldSelected = parseInt($(".calentim-timepicker-end .calentim-minute-selected").text(), 10);
		$(".calentim-timepicker-end .calentim-timepicker-minutes-up").trigger("click");
		var newSelected = parseInt($(".calentim-timepicker-end .calentim-minute-selected").text(), 10);
		// tests
		expect((oldSelected + 59) % 60).toEqual(newSelected);
	});
	it("should change the start AMPM", function () {
		this.input.click();
		if(this.calentim.globals.isMobile == false){
			$(".calentim-timepicker-start .calentim-timepicker-ampm-am, .calentim-timepicker-end .calentim-timepicker-ampm-am").click();
			expect(this.calentim.config.startDate.clone().format("a")).toBe("am");
			expect(this.calentim.config.endDate.clone().format("a")).toBe("am");
			$(".calentim-timepicker-start .calentim-timepicker-ampm-pm, .calentim-timepicker-end .calentim-timepicker-ampm-pm").click();
			expect(this.calentim.config.startDate.clone().format("a")).toBe("pm");
			expect(this.calentim.config.endDate.clone().format("a")).toBe("pm");
			$(".calentim-timepicker-start .calentim-timepicker-ampm-am, .calentim-timepicker-end .calentim-timepicker-ampm-am").click();
			expect(this.calentim.config.startDate.clone().format("a")).toBe("am");
			expect(this.calentim.config.endDate.clone().format("a")).toBe("am");
		}else{
			$(".calentim-timepicker-start .calentim-timepicker-ampm-am, .calentim-timepicker-end .calentim-timepicker-ampm-am").click();
			$(".calentim-apply").click();
			expect(this.calentim.config.startDate.clone().format("a")).toBe("am");
			expect(this.calentim.config.endDate.clone().format("a")).toBe("am");
			this.input.click();
			$(".calentim-timepicker-start .calentim-timepicker-ampm-pm, .calentim-timepicker-end .calentim-timepicker-ampm-pm").click();
			$(".calentim-apply").click();
			expect(this.calentim.config.startDate.clone().format("a")).toBe("pm");
			expect(this.calentim.config.endDate.clone().format("a")).toBe("pm");
			this.input.click();
			$(".calentim-timepicker-start .calentim-timepicker-ampm-am, .calentim-timepicker-end .calentim-timepicker-ampm-am").click();
			$(".calentim-apply").click();
			expect(this.calentim.config.startDate.clone().format("a")).toBe("am");
			expect(this.calentim.config.endDate.clone().format("a")).toBe("am");
		}
	});
	it("should set the range", function () {
		var days = this.calentim.input.find(".calentim-day");
		var startDay = $(days[Math.floor(Math.random() * days.length)]).first();
		var endDay = $(days[Math.floor(Math.random() * days.length)]).first();
		var startDate = moment.unix(startDay.attr("data-value"));
		var endDate = moment.unix(endDay.attr("data-value"));
		if (startDate.isAfter(endDate)) {
			var swapper = startDate.clone();
			startDate = endDate.clone();
			endDate = swapper.clone();
		}
		startDay.click();
		endDay.click();
		expect(this.calentim.config.startDate.isSame(startDate, "day")).toBe(true);
		expect(this.calentim.config.endDate.isSame(endDate, "day")).toBe(true);
	});

	describe("keyboard tests", function () {
		it("should move to the previous day", function () {
			this.input.click();
			this.calentim.globals.keyboardHoverDate = moment({ day: 15 });
			keyPressEvent = { type: 'keydown', which: 37, keyCode: 37 };
			this.calentim.calendars.focus();
			this.calentim.calendars.trigger(keyPressEvent);
			expect(this.calentim.globals.keyboardHoverDate.middleOfDay().format()).toEqual(moment({ day: 14 }).middleOfDay().format());
		});
		it("should move to the next day", function () {
			this.input.click();
			this.calentim.globals.keyboardHoverDate = moment({ day: 15 });
			var keyPressEvent = { type: 'keydown', which: 39, keyCode: 39 };
			this.calentim.calendars.focus();
			this.calentim.calendars.trigger(keyPressEvent);
			expect(this.calentim.globals.keyboardHoverDate.middleOfDay().format()).toEqual(moment({ day: 16 }).middleOfDay().format());
		});
		it("should move to the previous week", function () {
			this.input.click();
			this.calentim.globals.keyboardHoverDate = moment({ day: 15 });
			var keyPressEvent = { type: 'keydown', which: 38, keyCode: 38 };
			this.calentim.calendars.focus();
			this.calentim.calendars.trigger(keyPressEvent);
			expect(this.calentim.globals.keyboardHoverDate.format()).toEqual(moment({ day: 8 }).middleOfDay().format());
		});
		it("should move to the next week", function () {
			this.input.click();
			this.calentim.globals.keyboardHoverDate = moment({ day: 15 });
			var keyPressEvent = { type: 'keydown', which: 40, keyCode: 40 };
			this.calentim.calendars.focus();
			this.calentim.calendars.trigger(keyPressEvent);
			expect(this.calentim.globals.keyboardHoverDate.format()).toEqual(moment({ day: 22 }).middleOfDay().format());
		});
		it("should move to the previous month", function () {
			this.input.click();

			this.calentim.globals.keyboardHoverDate = moment({ day: 15 });
			var keyPressEvent = { type: 'keydown', which: 33, keyCode: 33 };
			this.calentim.calendars.focus();
			this.calentim.calendars.trigger(keyPressEvent);
			expect(this.calentim.globals.keyboardHoverDate.format("L LT")).toEqual(moment({day:15}).middleOfDay().subtract(moment.duration({month: 1})).middleOfDay().format("L LT"));

			this.calentim.globals.keyboardHoverDate = moment({ day: 1 });
			var keyPressEvent = { type: 'keydown', which: 38, keyCode: 38 };
			this.calentim.calendars.focus();
			this.calentim.calendars.trigger(keyPressEvent);
			expect(this.calentim.globals.keyboardHoverDate.month()).toEqual(moment({ day: 1 }).add(-1, "week").month());
		});
		it("should move to the next month", function () {
			this.input.click();

			this.calentim.globals.keyboardHoverDate = moment({ day: 15 });
			var keyPressEvent = { type: 'keydown', which: 34, keyCode: 34 };
			this.calentim.calendars.focus();
			this.calentim.calendars.trigger(keyPressEvent);
			expect(this.calentim.globals.keyboardHoverDate.format("L LT")).toEqual(moment({day:15}).middleOfDay().add(moment.duration({month: 1})).middleOfDay().format("L LT"));

			this.calentim.globals.keyboardHoverDate = moment({ day: 28 });
			var keyPressEvent = { type: 'keydown', which: 40, keyCode: 40 };
			this.calentim.calendars.focus();
			this.calentim.calendars.trigger(keyPressEvent);
			expect(this.calentim.globals.keyboardHoverDate.month()).toEqual(moment({ day: 28 }).add(1, "week").month());
		});
		it("should move to the previous year", function () {
			this.calentim.globals.keyboardHoverDate = moment({ day: 15 });
			this.input.click();
			var keyPressEvent = { shiftKey: true, type: 'keydown', which: 33, keyCode: 33 };
			this.calentim.calendars.focus();
			this.calentim.calendars.trigger(keyPressEvent);
			expect(this.calentim.globals.keyboardHoverDate.format()).toEqual(moment({ day: 15 }).add(-1, "year").middleOfDay().format());
		});
		it("should move to the next year", function () {
			this.calentim.globals.keyboardHoverDate = moment({ day: 15 });
			this.input.click();
			var keyPressEvent = { type: 'keydown', shiftKey: true, which: 34, keyCode: 34 };
			this.calentim.calendars.focus();
			this.calentim.calendars.trigger(keyPressEvent);
			expect(this.calentim.globals.keyboardHoverDate.format()).toEqual(moment({ day: 15 }).add(1, "year").middleOfDay().format());
		});
		it("should select the days with space", function(){
			var startDate = moment({ day: 15 }).middleOfDay();
			var endDate = moment({ day: 17 }).middleOfDay();
			this.input.focus();
			this.calentim.globals.keyboardHoverDate = startDate;
			var keyPressEvent = { type: 'keydown', shiftKey: true, which: 32, keyCode: 32 };
			this.calentim.calendars.focus();
			this.calentim.calendars.trigger(keyPressEvent);
			this.calentim.globals.keyboardHoverDate = endDate;
			var keyPressEvent = { type: 'keydown', shiftKey: true, which: 32, keyCode: 32 };
			this.calentim.calendars.focus();
			this.calentim.calendars.trigger(keyPressEvent);
			expect(this.calentim.config.startDate.clone().middleOfDay().format()).toEqual(startDate.format());
			expect(this.calentim.config.endDate.clone().middleOfDay().format()).toEqual(endDate.format());
		});
		it("should select the singleDate day with space", function(){
			this.calentim.config.singleDate = true;
			var startDate = moment({ day: 15 }).middleOfDay();
			this.calentim.globals.keyboardHoverDate = startDate;
			this.input.focus();
			var keyPressEvent = { type: 'keydown', shiftKey: true, which: 32, keyCode: 32 };
			this.calentim.calendars.focus();
			this.calentim.calendars.trigger(keyPressEvent);
			expect(this.calentim.config.startDate.clone().middleOfDay().format()).toEqual(startDate.format());
		});
		it("should close the dropdown with esc key", function(){
			this.input.focus();
			var keyPressEvent = { type: 'keydown', shiftKey: true, which: 27, keyCode: 27 };
			this.calentim.calendars.focus();
			this.calentim.calendars.trigger(keyPressEvent);
			expect(this.calentim.input.is(":visible")).toBe(false);
		});
		it("should go to the current month with home key", function(){
			this.calentim.globals.keyboardHoverDate = moment({ day: 15 });
			this.input.click();
			var keyPressEvent = { shiftKey: true, type: 'keydown', which: 33, keyCode: 33 };
			this.calentim.calendars.focus();
			this.calentim.calendars.trigger(keyPressEvent);
			expect(this.calentim.globals.keyboardHoverDate.format()).toEqual(moment({ day: 15 }).add(-1, "year").middleOfDay().format());
			var keyPressEvent = { shiftKey: false, type: 'keydown', which: 36, keyCode: 36 };
			this.calentim.calendars.focus();
			this.calentim.calendars.trigger(keyPressEvent);
			expect(this.calentim.globals.currentDate.year()).not.toEqual(moment().year());
			var keyPressEvent = { shiftKey: true, type: 'keydown', which: 36, keyCode: 36 };
			this.calentim.calendars.focus();
			this.calentim.calendars.trigger(keyPressEvent);
			expect(this.calentim.globals.currentDate.year()).toEqual(moment().year());
		});
	})
});

describe("config tests", function () {
	beforeEach(function () {
		this.input = $("<input type='text' class='calentim-test-input' />").appendTo("body");
	});
	afterEach(function () {
		this.input = $(".calentim-test-input");
		this.input.data("calentim").destroy();
		this.calentim = null;
		this.input.remove();
	});

	it("should have the defined count of calendars", function () {
		this.input.calentim({ calendarCount: 4 });
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.find(".calentim-calendar").length).toBe(4);
	});
	it("should be visible when inline is defined", function () {
		this.input.calentim({ inline: true });
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.is(":visible")).toBe(true);
	});
	it("should have mindate effective", function () {
		this.input.calentim({ minDate: moment() });
		this.calentim = this.input.data("calentim");
		expect(this.calentim.calendars.find("div[data-value='" + moment().subtract(1, "days").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
	});
	it("should have maxdate effective", function () {
		this.input.calentim({ maxDate: moment() });
		this.calentim = this.input.data("calentim");
		expect(this.calentim.calendars.find("div[data-value='" + moment().add(1, "days").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
	});
	it("should have the start date and end date effective", function () {
		var startDate = moment("24/05/1984 08:25", "DD/MM/YYYY HH:mm");
		var endDate = moment("25/05/1984 08:26", "DD/MM/YYYY HH:mm");
		this.input.calentim({ startDate: startDate, endDate: endDate });
		this.calentim = this.input.data("calentim");
		expect(this.calentim.config.startDate.format()).toEqual(moment("24/05/1984 08:25", "DD/MM/YYYY HH:mm").format());
		expect(this.calentim.config.endDate.format()).toEqual(moment("25/05/1984 08:26", "DD/MM/YYYY HH:mm").format());
		this.input.click();
		expect(this.calentim.input.find(".calentim-calendar:first").data("month").toString()).toEqual(startDate.month().toString());
		expect(this.calentim.input.find(".calentim-day[data-value='" + startDate.clone().middleOfDay().unix() + "']").hasClass("calentim-start")).toEqual(true);
		expect(this.calentim.input.find(".calentim-day[data-value='" + endDate.clone().middleOfDay().unix() + "']").hasClass("calentim-end")).toEqual(true);
		if (this.calentim.config.hourFormat !== 12) {
			expect(this.calentim.input.find(".calentim-timepicker-start .calentim-timepicker-hours .calentim-hour-selected").text()).toEqual(startDate.hours().toString());
			expect(this.calentim.input.find(".calentim-timepicker-start .calentim-timepicker-minutes .calentim-minute-selected").text()).toEqual(startDate.minutes().toString());
			expect(this.calentim.input.find(".calentim-timepicker-end .calentim-timepicker-hours .calentim-hour-selected").text()).toEqual(endDate.hours().toString());
			expect(this.calentim.input.find(".calentim-timepicker-end .calentim-timepicker-minutes .calentim-minute-selected").text()).toEqual(endDate.minutes().toString());
		} else {
			var starts = startDate.format("hh mm a").split(" ");
			var ends = endDate.format("hh mm a").split(" ");
			expect(this.calentim.input.find(".calentim-timepicker-start .calentim-timepicker-hours .calentim-hour-selected").text()).toEqual(starts[0]);
			expect(this.calentim.input.find(".calentim-timepicker-start .calentim-timepicker-minutes .calentim-minute-selected").text()).toEqual(starts[1]);
			expect(this.calentim.input.find(".calentim-timepicker-start .calentim-ampm-selected").text().toLowerCase()).toEqual(starts[2]);
			expect(this.calentim.input.find(".calentim-timepicker-end .calentim-timepicker-hours .calentim-hour-selected").text()).toEqual(ends[0]);
			expect(this.calentim.input.find(".calentim-timepicker-end .calentim-timepicker-minutes .calentim-minute-selected").text()).toEqual(ends[1]);
			expect(this.calentim.input.find(".calentim-timepicker-end .calentim-ampm-selected").text().toLowerCase()).toEqual(ends[2]);
		}
	});
	it("should respect the mindate on start date", function () {
		this.input.calentim({
			startDate: moment("22/11/2017 12:00:00","dd/MM/YYYY HH:mm:ss"),
			endDate: moment("28/11/2017 12:00:00","dd/MM/YYYY HH:mm:ss"),
			minDate: moment("24/11/2017 12:00:00","dd/MM/YYYY HH:mm:ss")
		});
		this.calentim = this.input.data("calentim");
		this.input.click();
		expect(this.calentim.config.startDate.inspect()).toBe(this.calentim.config.minDate.inspect());
	});
	it("should respect the mindate on end date", function () {
		this.input.calentim({
			startDate: moment("22/11/2017 12:00:00","dd/MM/YYYY HH:mm:ss"),
			endDate: moment("28/11/2017 12:00:00","dd/MM/YYYY HH:mm:ss"),
			minDate: moment("20/11/2017 12:00:00","dd/MM/YYYY HH:mm:ss")
		});
		this.calentim = this.input.data("calentim");
		this.input.click();
		expect(this.calentim.config.startDate.inspect()).toBe(this.calentim.config.minDate.inspect());
		expect(this.calentim.config.endDate.inspect()).toBe(this.calentim.config.minDate.inspect());
	});
	it("should respect the maxdate on end date", function () {
		this.input.calentim({
			startDate: moment("22/11/2017 12:00:00","dd/MM/YYYY HH:mm:ss"),
			endDate: moment("28/11/2017 12:00:00","dd/MM/YYYY HH:mm:ss"),
			maxDate: moment("24/11/2017 12:00:00","dd/MM/YYYY HH:mm:ss")
		});
		this.calentim = this.input.data("calentim");
		this.input.click();
		expect(this.calentim.config.endDate.inspect()).toBe(this.calentim.config.maxDate.inspect());
	});
	it("should respect the maxdate on start date", function () {
		this.input.calentim({
			startDate: moment("22/11/2017 12:00:00","dd/MM/YYYY HH:mm:ss"),
			endDate: moment("28/11/2017 12:00:00","dd/MM/YYYY HH:mm:ss"),
			maxDate: moment("20/11/2017 12:00:00","dd/MM/YYYY HH:mm:ss")
		});
		this.calentim = this.input.data("calentim");
		this.input.click();
		expect(this.calentim.config.startDate.inspect()).toBe(this.calentim.config.maxDate.inspect());
		expect(this.calentim.config.endDate.inspect()).toBe(this.calentim.config.maxDate.inspect());
	});
	it("should swap the start and end date", function () {
		var startDate = moment("20/11/2017 12:00:00","dd/MM/YYYY HH:mm:ss");
		var endDate = moment("15/11/2017 12:00:00","dd/MM/YYYY HH:mm:ss");
		this.input.calentim({
			startDate: startDate,
			endDate: endDate
		});
		this.calentim = this.input.data("calentim");
		this.input.click();
		expect(this.calentim.config.startDate.inspect()).toBe(endDate.inspect());
		expect(this.calentim.config.endDate.inspect()).toBe(startDate.inspect());
	});
	it("should hide the calendar header", function () {
		this.input.calentim({
			showHeader: false
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.find(".calentim-header").length).toBe(1);
		expect(this.calentim.input.find(".calentim-header").is(":visible")).toBe(false);
	});
	it("should not add the calendar footer", function () {
		this.input.calentim({
			showFooter: false
		});
		this.calentim = this.input.data("calentim");
		if (this.calentim.globals.isMobile === false)
			expect(this.calentim.input.find(".calentim-footer").length).toBe(0);
		else
			expect(this.calentim.input.find(".calentim-footer").length).toBe(1);
	});
	it("should have 12 hour format", function () {
		this.input.calentim({
			hourFormat: 12
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.find(".calentim-timepicker-ampm").length).toBe(2);
		var startHours = this.calentim.input.find(".calentim-timepicker-start .calentim-timepicker-hours");
		var endHours = this.calentim.input.find(".calentim-timepicker-end .calentim-timepicker-hours");
		expect(startHours.data("max")).toBe(12);
		expect(startHours.data("min")).toBe(1);
		expect(endHours.data("max")).toBe(12);
		expect(endHours.data("min")).toBe(1);
	});
	it("should have 24 hour format", function () {
		this.input.calentim({
			hourFormat: 24
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.find(".calentim-timepicker-ampm").length).toBe(0);
		var startHours = this.calentim.input.find(".calentim-timepicker-start .calentim-timepicker-hours");
		var endHours = this.calentim.input.find(".calentim-timepicker-end .calentim-timepicker-hours");
		expect(startHours.data("max")).toBe(23);
		expect(startHours.data("min")).toBe(0);
		expect(endHours.data("max")).toBe(23);
		expect(endHours.data("min")).toBe(0);
	});
	it("should respect the minute steps", function () {
		this.input.calentim({
			minuteSteps: 15
		});
		this.calentim = this.input.data("calentim");
		var startMinutes = this.calentim.input.find(".calentim-timepicker-start .calentim-timepicker-minutes");
		var endMinutes = this.calentim.input.find(".calentim-timepicker-end .calentim-timepicker-minutes");
		expect(startMinutes.data("step")).toBe(15);
		expect(endMinutes.data("step")).toBe(15);
		// effect
		var oldSelected = parseInt($(".calentim-timepicker-start .calentim-minute-selected").text(), 10);
		$(".calentim-timepicker-start .calentim-timepicker-minutes-down").trigger("click");
		var newSelected = parseInt($(".calentim-timepicker-start .calentim-minute-selected").text(), 10);
		// tests
		if (oldSelected + 15 > startMinutes.data("max")) expect(newSelected).toEqual(startMinutes.data('min'));
		else expect(oldSelected + 15).toEqual(newSelected);
		// effect
		oldSelected = parseInt($(".calentim-timepicker-start .calentim-minute-selected").text(), 10);
		$(".calentim-timepicker-start .calentim-timepicker-minutes-up").trigger("click");
		newSelected = parseInt($(".calentim-timepicker-start .calentim-minute-selected").text(), 10);
		// tests
		if (oldSelected - 15 < startMinutes.data("min")) expect(newSelected).toEqual(startMinutes.data('max'));
		else expect(oldSelected - 15).toEqual(newSelected);
	});
	it("should start on Monday", function () {
		this.input.calentim({
			startOnMonday: true
		});
		this.calentim = this.input.data("calentim");
		var calendarStart = this.calentim.input.find(".calentim-calendar:first").find(".calentim-disabled, .calentim-day").first().attr("data-value");
		var calendarStartMoment = moment.unix(calendarStart);
		expect(calendarStartMoment.isoWeekday()).toBe(1);
	});
	it("should start on Sunday", function () {
		this.input.calentim({
			startOnMonday: false
		});
		this.calentim = this.input.data("calentim");
		var calendarStart = this.calentim.input.find(".calentim-calendar:first").find(".calentim-disabled, .calentim-day").first().attr("data-value");
		var calendarStartMoment = moment.unix(calendarStart);
		expect(calendarStartMoment.isoWeekday()).toBe(7);
	});
	it("should start empty", function () {
		this.input.calentim({
			startEmpty: true
		});
		this.calentim = this.input.data("calentim");
		expect(this.input.val()).toBe("");
		this.input.click();
		$("body").click();
		expect(this.input.val()).toBe("");
		this.input.click();
		this.calentim.calendars.find(".calentim-calendar:first .calentim-day:first").click();
		this.calentim.calendars.find(".calentim-calendar:first .calentim-day:last").click();
		if (this.calentim.globals.isMobile)
			$(".calentim-apply").click();
		else
			$("body").click();
		expect(this.input.val()).not.toBe("");
	});
	it("should close when selection occurs (autocloseonselect)", function () {
		this.input.calentim({
			autoCloseOnSelect: true
		});
		this.calentim = this.input.data("calentim");
		this.input.click();
		expect(this.calentim.input.is(":visible")).toBe(true);
		this.calentim.calendars.find(".calentim-calendar:first .calentim-day:first").click();
		this.calentim.calendars.find(".calentim-calendar:first .calentim-day:last").click();
		expect(this.input.val()).not.toBe("");
		if (!this.calentim.globals.isMobile)
			expect(this.calentim.container.is(":visible")).toBe(false);
		else
			expect(this.calentim.input.is(":visible")).toBe(false);
	});
	it("should not close when inline view selection occurs (autocloseonselect)", function () {
		this.input.calentim({
			autoCloseOnSelect: true,
			inline: true
		});
		this.calentim = this.input.data("calentim");
		expect($(".calentim-input").is(":visible")).toBe(true);
		this.calentim.calendars.find(".calentim-calendar:first .calentim-day:first").click();
		this.calentim.calendars.find(".calentim-calendar:first .calentim-day:last").click();
		expect(this.input.val()).not.toBe("");
		expect($(".calentim-input").is(":visible")).toBe(true);
	});
	it("should disable days by function", function () {
		this.input.calentim({
			disableDays: function (day) {
				return day.isSame(moment(), "day");
			}
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.is(":visible")).toBe(false);
		this.input.click();
		expect(this.calentim.calendars.find("[data-value='" + moment().middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
		expect(this.calentim.input.is(":visible")).toBe(true);
	});
	it("should disable days by array", function () {
		this.input.calentim({
			disabledRanges: [{
				start: moment().add(1, "day"),
				end: moment().add(3, "day")
			}]
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.is(":visible")).toBe(false);
		this.input.click();
		expect(this.calentim.calendars.find("[data-value='" + moment().add(1, "day").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
		expect(this.calentim.calendars.find("[data-value='" + moment().add(2, "day").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
		expect(this.calentim.calendars.find("[data-value='" + moment().add(3, "day").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
		expect(this.calentim.input.is(":visible")).toBe(true);
	});
	it("should preserve continuousity when disabling days by function", function () {
		var dayStart = 15;
		if(moment().date() == 14 || moment().date() == 15 || moment().date() == 16){
			dayStart = 22;
		}
		this.input.calentim({
			disableDays: function (day) {
				return day.isSame(moment({ day: dayStart }), "day");
			},
			continuous: true
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.is(":visible")).toBe(false);
		this.input.click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(-1, "day").unix() + "']").first().click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(-2, "day").unix() + "']").first().click();
		expect(this.calentim.config.startDate.isSame(moment({ day: dayStart }).middleOfDay().add(-2, "day"), "day")).toBe(true);
		expect(this.calentim.config.endDate.isSame(moment({ day: dayStart }).middleOfDay().add(-1, "day"), "day")).toBe(true);
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(-1, "day").unix() + "']").first().click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(1, "day").unix() + "']").first().click();
		if(this.calentim.globals.isMobile == false){
			expect(this.calentim.config.startDate.isSame(moment({ day: dayStart }).middleOfDay().add(-2, "day"), "day")).toBe(true);
			expect(this.calentim.config.endDate.isSame(moment({ day: dayStart }).middleOfDay().add(-1, "day"), "day")).toBe(true);
		}else{
			expect(this.calentim.config.startDate.isSame(moment(), "day")).toBe(true);
			expect(this.calentim.config.endDate.isSame(moment(), "day")).toBe(true);
		}
		expect(this.calentim.input.is(":visible")).toBe(true);
	});
	it("should preserve continuousity when disabling days by array", function () {
		var dayStart = 15;
		if(moment().date() == 14 || moment().date() == 15 || moment().date() == 16){
			dayStart = 22;
		}
		this.input.calentim({
			disabledRanges: [{
				start: moment({ day: dayStart }),
				end: moment({ day: dayStart }).add(1, "day")
			}],
			continuous: true
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.is(":visible")).toBe(false);
		this.input.click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(-1, "day").unix() + "']").first().click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(-2, "day").unix() + "']").first().click();
		expect(this.calentim.config.startDate.isSame(moment({ day: dayStart }).middleOfDay().add(-2, "day"), "day")).toBe(true);
		expect(this.calentim.config.endDate.isSame(moment({ day: dayStart }).middleOfDay().add(-1, "day"), "day")).toBe(true);
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(-1, "day").unix() + "']").first().click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(3, "day").unix() + "']").first().click();
		if(this.calentim.globals.isMobile == false){
			expect(this.calentim.config.startDate.isSame(moment({ day: dayStart }).middleOfDay().add(-2, "day"), "day")).toBe(true);
			expect(this.calentim.config.endDate.isSame(moment({ day: dayStart }).middleOfDay().add(-1, "day"), "day")).toBe(true);
		}else{
			expect(this.calentim.config.startDate.isSame(moment(), "day")).toBe(true);
			expect(this.calentim.config.endDate.isSame(moment(), "day")).toBe(true);
		}
		expect(this.calentim.input.is(":visible")).toBe(true);
	});
	it("should preserve continuousity when disabling days by function (startEmpty - no initial selection)", function () {
		var dayStart = 15;
		if(moment().date() == 14 || moment().date() == 15 || moment().date() == 16){
			dayStart = 22;
		}
		this.input.calentim({
			disableDays: function (day) {
				return day.isSame(moment({ day: dayStart }), "day");
			},
			continuous: true,
			startEmpty: true
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.is(":visible")).toBe(false);
		this.input.click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(-1, "day").unix() + "']").first().click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(1, "day").unix() + "']").first().click();
		expect(this.calentim.config.startDate).toBe(null);
		expect(this.calentim.config.endDate).toBe(null);
		expect(this.calentim.input.is(":visible")).toBe(true);
	});
	it("should preserve continuousity when disabling days by array (startEmpty - no initial selection)", function () {
		var dayStart = 15;
		if(moment().date() == 14 || moment().date() == 15 || moment().date() == 16){
			dayStart = 22;
		}
		this.input.calentim({
			disabledRanges: [{
				start: moment({ day: dayStart }),
				end: moment({ day: dayStart }).add(1, "day")
			}],
			continuous: true,
			startEmpty: true
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.is(":visible")).toBe(false);
		this.input.click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(-1, "day").unix() + "']").first().click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(3, "day").unix() + "']").first().click();
		expect(this.calentim.config.startDate).toBe(null);
		expect(this.calentim.config.endDate).toBe(null);
		expect(this.calentim.input.is(":visible")).toBe(true);
	});

	it("should preserve continuousity when disabling days by function (startEmpty - no initial selection)", function () {
		var dayStart = 15;
		if(moment().date() == 14 || moment().date() == 15 || moment().date() == 16){
			dayStart = 22;
		}
		this.input.calentim({
			disableDays: function (day) {
				return day.isSame(moment({ day: dayStart }), "day");
			},
			continuous: true,
			startEmpty: true
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.is(":visible")).toBe(false);
		this.input.click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(-1, "day").unix() + "']").first().click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(1, "day").unix() + "']").first().click();
		expect(this.calentim.config.startDate).toBe(null);
		expect(this.calentim.config.endDate).toBe(null);
		expect(this.calentim.input.is(":visible")).toBe(true);
	});

	it("should preserve continuousity when disabling days by array (no initial selection)", function () {
		var dayStart = 15;
		if(moment().date() == 14 || moment().date() == 15 || moment().date() == 16){
			dayStart = 22;
		}
		this.input.calentim({
			disabledRanges: [{
				start: moment({ day: dayStart }),
				end: moment({ day: dayStart }).add(1, "day")
			}],
			continuous: true
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.is(":visible")).toBe(false);
		this.input.click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(-1, "day").unix() + "']").first().click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(3, "day").unix() + "']").first().click();
		expect(this.calentim.config.startDate.format("L")).toBe(moment().format("L"));
		expect(this.calentim.config.endDate.format("L")).toBe(moment().format("L"));
		expect(this.calentim.input.is(":visible")).toBe(true);
	});

	it("should preserve continuousity when disabling days by function (no initial selection)", function () {
		var dayStart = 15;
		if(moment().date() == 14 || moment().date() == 15 || moment().date() == 16){
			dayStart = 22;
		}
		this.input.calentim({
			disableDays: function (day) {
				return day.isSame(moment({ day: dayStart }), "day");
			},
			continuous: true
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.is(":visible")).toBe(false);
		this.input.click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(-1, "day").unix() + "']").first().click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(1, "day").unix() + "']").first().click();
		expect(this.calentim.config.startDate.format("L")).toBe(moment().middleOfDay().format("L"));
		expect(this.calentim.config.endDate.format("L")).toBe(moment().middleOfDay().format("L"));
		expect(this.calentim.input.is(":visible")).toBe(true);
	});
	it("shouldn't change the datetime on the input until apply button is clicked", function () {
		var dayStart = 15;
		if(moment().date() == 14 || moment().date() == 15 || moment().date() == 16){
			dayStart = 22;
		}
		this.input.calentim({
			showButtons: true
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.is(":visible")).toBe(false);
		this.input.click();
		expect(this.calentim.input.is(":visible")).toBe(true);
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(-1, "day").unix() + "']").first().click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(1, "day").unix() + "']").first().click();
		expect(this.calentim.config.startDate.format("L")).toBe(moment({ day: dayStart - 1 }).format("L"));
		expect(this.calentim.config.endDate.format("L")).toBe(moment({ day: dayStart + 1 }).format("L"));
		expect(this.calentim.$elem.val()).not.toBe(this.calentim.config.startDate.format(this.calentim.config.format) + this.calentim.config.dateSeparator + this.calentim.config.endDate.format(this.calentim.config.format));
		expect(this.calentim.$elem.val()).toBe(moment().format(this.calentim.config.format) + this.calentim.config.dateSeparator + moment().format(this.calentim.config.format));
		expect(this.calentim.input.find(".calentim-apply").length).toEqual(1);
		this.calentim.input.find(".calentim-apply").click();
		expect(this.calentim.$elem.val()).toBe(this.calentim.config.startDate.format(this.calentim.config.format) + this.calentim.config.dateSeparator + this.calentim.config.endDate.format(this.calentim.config.format));
		if (!this.calentim.globals.isMobile)
			expect(this.calentim.container.is(":visible")).toBe(false);
		else
			expect(this.calentim.input.is(":visible")).toBe(false);
	});
});