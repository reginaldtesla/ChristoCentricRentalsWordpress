describe("initial testing inline", function () {
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
			},
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

	it("should have the dropdown visible", function () {
		expect($(".calentim-input").is(":visible")).toBe(true);
	});

	it("should have the custom date set on the this.input", function () {
		this.input.val(moment({ year: 2016, month: 2, day: 13, hour: 14, minute: 37 }).format(this.calentim.config.format) +
			this.calentim.config.dateSeparator +
			moment({ year: 2016, month: 4, day: 24, hour: 11, minute: 35 }).format(this.calentim.config.format));
		this.calentim.reDrawCalendars();
		expect(this.input.val()).toEqual(this.calentim.config.startDate.clone().format(this.calentim.config.format) +
			this.calentim.config.dateSeparator +
			this.calentim.config.endDate.clone().format(this.calentim.config.format));
	});

	it("should have the correct months visible and days selected on the calendar", function () {
		expect(this.element.find(".calentim-calendar").length).toEqual(this.calentim.config.calendarCount);
		expect(this.element.find(".calentim-calendar:first").data("month")).toEqual(this.calentim.config.startDate.month());
		expect(this.element.find(".calentim-calendar:last").data("month")).toEqual(this.calentim.config.startDate.clone().add(this.calentim.config.calendarCount - 1, "months").month());
		expect(this.element.find(".calentim-day[data-value='" + this.calentim.config.startDate.clone().middleOfDay().unix() + "']").hasClass("calentim-start")).toBe(true);
		expect(this.element.find(".calentim-day[data-value='" + this.calentim.config.endDate.clone().middleOfDay().unix() + "']").hasClass("calentim-end")).toBe(true);
		expect(this.element.find(".calentim-day[data-value='" + this.calentim.config.endDate.clone().middleOfDay().unix() + "']").hasClass("calentim-selected")).toBe(true);
		expect(this.element.find(".calentim-day[data-value='" + this.calentim.config.endDate.clone().middleOfDay().unix() + "']").hasClass("calentim-selected")).toBe(true);
	});

	it("should have the correct time visible on the calendar", function () {
		if (this.calentim.config.hourFormat !== 12) {
			expect(this.element.find(".calentim-timepicker-start .calentim-timepicker-hours .calentim-hour-selected").text()).toEqual(this.calentim.config.startDate.hours());
			expect(this.element.find(".calentim-timepicker-start .calentim-timepicker-minutes .calentim-minute-selected").text()).toEqual(this.calentim.config.startDate.minutes());
			expect(this.element.find(".calentim-timepicker-end .calentim-timepicker-hours .calentim-hour-selected").text()).toEqual(this.calentim.config.endDate.hours());
			expect(this.element.find(".calentim-timepicker-end .calentim-timepicker-minutes .calentim-minute-selected").text()).toEqual(this.calentim.config.endDate.minutes());
		} else {
			var starts = this.calentim.config.startDate.format("hh mm a").split(" ");
			var ends = this.calentim.config.endDate.format("hh mm a").split(" ");
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
		// until mobile inline view is available, open the input.

		this.calentim.config.endDate.add(2, "day");
		for (var i = 0; i < 25; i++) {
			// effect
			var oldSelected = parseInt($(".calentim-timepicker-start .calentim-hour-selected").text(), 10);
			$(".calentim-timepicker-start .calentim-timepicker-hours-down").click();
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
		// until mobile inline view is available, open the input.
		this.calentim.config.endDate.add(2, "day");
		for (var i = 0; i < 25; i++) {
			// effect
			var oldSelected = parseInt($(".calentim-timepicker-end .calentim-hour-selected").text(), 10);
			$(".calentim-timepicker-end .calentim-timepicker-hours-down").click();
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
		// until mobile inline view is available, open the input.

		this.calentim.config.endDate.add(2, "day");
		for (var i = 0; i < 25; i++) {
			// effect
			var oldSelected = parseInt($(".calentim-timepicker-start .calentim-hour-selected").text(), 10);
			$(".calentim-timepicker-start .calentim-timepicker-hours-up").click();
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
		// until mobile inline view is available, open the input.

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
		for (var i = 0; i < 70; i++) {
			var oldSelected = parseInt($(".calentim-timepicker-start .calentim-minute-selected").text(), 10);
			$(".calentim-timepicker-start .calentim-timepicker-minutes-down").trigger("click");
			var newSelected = parseInt($(".calentim-timepicker-start .calentim-minute-selected").text(), 10);
			// tests
			expect((oldSelected + 1) % 60).toEqual(newSelected);
		}
	});
	it("should increase the end minute", function () {
		// effect
		for (var i = 0; i < 70; i++) {
			var oldSelected = parseInt($(".calentim-timepicker-end .calentim-minute-selected").text(), 10);
			$(".calentim-timepicker-end .calentim-timepicker-minutes-down").trigger("click");
			var newSelected = parseInt($(".calentim-timepicker-end .calentim-minute-selected").text(), 10);
			// tests
			expect((oldSelected + 1) % 60).toEqual(newSelected);
		}
	});
	it("should decrease the start minute", function () {
		// effect
		for (var i = 0; i < 70; i++) {
			var oldSelected = parseInt($(".calentim-timepicker-start .calentim-minute-selected").text(), 10);
			$(".calentim-timepicker-start .calentim-timepicker-minutes-up").trigger("click");
			var newSelected = parseInt($(".calentim-timepicker-start .calentim-minute-selected").text(), 10);
			// tests
			expect((oldSelected + 59) % 60).toEqual(newSelected);
		}
	});
	it("should decrease the end minute", function () {
		// effect
		for (var i = 0; i < 70; i++) {
			var oldSelected = parseInt($(".calentim-timepicker-end .calentim-minute-selected").text(), 10);
			$(".calentim-timepicker-end .calentim-timepicker-minutes-up").trigger("click");
			var newSelected = parseInt($(".calentim-timepicker-end .calentim-minute-selected").text(), 10);
			// tests
			expect((oldSelected + 59) % 60).toEqual(newSelected);
		}
	});

	it("should increase the start hour reverse", function () {
		// set end date to next day to prevent swapping
		// until mobile inline view is available, open the input.
		this.calentim.config.reverseTimepickerArrows = true;
		this.calentim.reDrawCalendars();
		this.calentim.config.endDate.add(2, "day");
		for (var i = 0; i < 25; i++) {
			// effect
			var oldSelected = parseInt($(".calentim-timepicker-start .calentim-hour-selected").text(), 10);
			$(".calentim-timepicker-start .calentim-timepicker-hours-up").click();
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
	it("should increase the end hour reverse", function () {
		// set end date to next day to prevent swapping
		// until mobile inline view is available, open the input.
		this.calentim.config.reverseTimepickerArrows = true;
		this.calentim.reDrawCalendars();
		this.calentim.config.endDate.add(2, "day");
		for (var i = 0; i < 25; i++) {
			// effect
			var oldSelected = parseInt($(".calentim-timepicker-end .calentim-hour-selected").text(), 10);
			$(".calentim-timepicker-end .calentim-timepicker-hours-up").click();
			var newSelected = parseInt($(".calentim-timepicker-end .calentim-hour-selected").text(), 10);
			// tests
			if (this.calentim.config.hourFormat === 12 && oldSelected === 12) expect(newSelected).toEqual(1);
			else if (this.calentim.config.hourFormat !== 12 && oldSelected === 23) expect(newSelected).toEqual(0);
			else expect(oldSelected + 1).toEqual(newSelected);
			if (this.calentim.config.hourFormat == 12) expect(newSelected).toEqual(parseInt(this.calentim.config.endDate.clone().format("h"), 10));
			else expect(newSelected).toEqual(parseInt(this.calentim.config.endDate.clone().format("H"), 10));
		}
	});
	it("should decrease the start hour reverse", function () {
		// set end date to next day to prevent swapping
		// until mobile inline view is available, open the input.
		this.calentim.config.reverseTimepickerArrows = true;
		this.calentim.reDrawCalendars();
		this.calentim.config.endDate.add(2, "day");
		for (var i = 0; i < 25; i++) {
			// effect
			var oldSelected = parseInt($(".calentim-timepicker-start .calentim-hour-selected").text(), 10);
			$(".calentim-timepicker-start .calentim-timepicker-hours-down").click();
			var newSelected = parseInt($(".calentim-timepicker-start .calentim-hour-selected").text(), 10);
			// tests
			if (this.calentim.config.hourFormat == 12 && oldSelected === 1) expect(newSelected).toEqual(12);
			else if (this.calentim.config.hourFormat != 12 && oldSelected === 0) expect(newSelected).toEqual(23);
			else expect(oldSelected - 1).toEqual(newSelected);
			if (this.calentim.config.hourFormat == 12) expect(newSelected).toEqual(parseInt(this.calentim.config.startDate.clone().format("h"), 10));
			else expect(newSelected).toEqual(parseInt(this.calentim.config.startDate.clone().format("H"), 10));
		}
	});
	it("should decrease the end hour reverse", function () {
		// set end date to next day to prevent swapping
		// until mobile inline view is available, open the input.
		this.calentim.config.reverseTimepickerArrows = true;
		this.calentim.reDrawCalendars();
		this.calentim.config.endDate.add(2, "day");
		// effect
		for (var i = 0; i < 25; i++) {
			var oldSelected = parseInt($(".calentim-timepicker-end .calentim-hour-selected").text(), 10);
			$(".calentim-timepicker-end .calentim-timepicker-hours-down").trigger("click");
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
	it("should increase the start minute reverse", function () {
		// effect
		this.calentim.config.reverseTimepickerArrows = true;
		this.calentim.reDrawCalendars();
		for (var i = 0; i < 70; i++) {
			var oldSelected = parseInt($(".calentim-timepicker-start .calentim-minute-selected").text(), 10);
			$(".calentim-timepicker-start .calentim-timepicker-minutes-up").trigger("click");
			var newSelected = parseInt($(".calentim-timepicker-start .calentim-minute-selected").text(), 10);
			// tests
			expect((oldSelected + 1) % 60).toEqual(newSelected);
		}
	});
	it("should increase the end minute reverse", function () {
		// effect
		this.calentim.config.reverseTimepickerArrows = true;
		this.calentim.reDrawCalendars();
		for (var i = 0; i < 70; i++) {
			var oldSelected = parseInt($(".calentim-timepicker-end .calentim-minute-selected").text(), 10);
			$(".calentim-timepicker-end .calentim-timepicker-minutes-up").trigger("click");
			var newSelected = parseInt($(".calentim-timepicker-end .calentim-minute-selected").text(), 10);
			// tests
			expect((oldSelected + 1) % 60).toEqual(newSelected);
		}
	});
	it("should decrease the start minute reverse", function () {
		// effect
		this.calentim.config.reverseTimepickerArrows = true;
		this.calentim.reDrawCalendars();
		for (var i = 0; i < 70; i++) {
			var oldSelected = parseInt($(".calentim-timepicker-start .calentim-minute-selected").text(), 10);
			$(".calentim-timepicker-start .calentim-timepicker-minutes-down").trigger("click");
			var newSelected = parseInt($(".calentim-timepicker-start .calentim-minute-selected").text(), 10);
			// tests
			expect((oldSelected + 59) % 60).toEqual(newSelected);
		}
	});
	it("should decrease the end minute reverse", function () {
		// effect
		this.calentim.config.reverseTimepickerArrows = true;
		this.calentim.reDrawCalendars();
		for (var i = 0; i < 70; i++) {
			var oldSelected = parseInt($(".calentim-timepicker-end .calentim-minute-selected").text(), 10);
			$(".calentim-timepicker-end .calentim-timepicker-minutes-down").trigger("click");
			var newSelected = parseInt($(".calentim-timepicker-end .calentim-minute-selected").text(), 10);
			// tests
			expect((oldSelected + 59) % 60).toEqual(newSelected);
		}
	});
	it("should change the start AMPM", function () {
		$(".calentim-timepicker-start .calentim-timepicker-ampm-am, .calentim-timepicker-end .calentim-timepicker-ampm-am").click();
		expect(this.calentim.config.startDate.clone().format("a")).toBe("am");
		expect(this.calentim.config.endDate.clone().format("a")).toBe("am");
		$(".calentim-timepicker-start .calentim-timepicker-ampm-pm, .calentim-timepicker-end .calentim-timepicker-ampm-pm").click();
		expect(this.calentim.config.startDate.clone().format("a")).toBe("pm");
		expect(this.calentim.config.endDate.clone().format("a")).toBe("pm");
		$(".calentim-timepicker-start .calentim-timepicker-ampm-am, .calentim-timepicker-end .calentim-timepicker-ampm-am").click();
		expect(this.calentim.config.startDate.clone().format("a")).toBe("am");
		expect(this.calentim.config.endDate.clone().format("a")).toBe("am");
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
		this.input.calentim({
			calendarCount: 4,
			inline: true
		});
		this.calentim = this.input.data("calentim");

		expect($(".calentim-input").is(":visible")).toBe(true);
		expect(this.calentim.input.find(".calentim-calendar").length).toBe(4);
	});
	it("should be visible when inline is defined", function () {
		this.input.calentim({
			inline: true
		});
		this.calentim = this.input.data("calentim");

		expect($(".calentim-input").is(":visible")).toBe(true);
	});
	it("should have mindate effective", function () {
		this.input.calentim({
			minDate: moment(),
			inline: true
		});
		this.calentim = this.input.data("calentim");

		expect($(".calentim-input").is(":visible")).toBe(true);
		expect(this.calentim.calendars.find("div[data-value='" + moment().subtract(1, "days").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
	});
	it("should have maxdate effective", function () {
		this.input.calentim({
			maxDate: moment(),
			inline: true
		});
		this.calentim = this.input.data("calentim");

		expect($(".calentim-input").is(":visible")).toBe(true);
		expect(this.calentim.calendars.find("div[data-value='" + moment().add(1, "days").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
	});
	it("should change the mindate with function", function () {
		this.input.calentim({
			minDate: moment(),
			inline: true
		});
		this.calentim = this.input.data("calentim");
		this.calentim.setMinDate(moment().add(1,"days"));
		expect($(".calentim-input").is(":visible")).toBe(true);
		expect(this.calentim.calendars.find("div[data-value='" + moment().middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
	});
	it("should change the maxdate with function", function () {
		this.input.calentim({
			maxDate: moment(),
			inline: true
		});
		this.calentim = this.input.data("calentim");
		this.calentim.setMaxDate(moment().add(-1,"days"));
		expect($(".calentim-input").is(":visible")).toBe(true);
		expect(this.calentim.calendars.find("div[data-value='" + moment().middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
	});
	it("should have the start date and end date effective", function () {
		var startDate = moment("24/05/1984 08:25", "DD/MM/YYYY HH:mm");
		var endDate = moment("25/05/1984 08:26", "DD/MM/YYYY HH:mm");
		this.input.calentim({ startDate: startDate, endDate: endDate, inline: true });
		this.calentim = this.input.data("calentim");
		expect(this.calentim.config.startDate.format()).toEqual(moment("24/05/1984 08:25", "DD/MM/YYYY HH:mm").format());
		expect(this.calentim.config.endDate.format()).toEqual(moment("25/05/1984 08:26", "DD/MM/YYYY HH:mm").format());
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
	it("should read the start date and end date from input", function () {
		var startDate = moment("24/05/1984 08:25", "DD/MM/YYYY HH:mm");
		var endDate = moment("25/05/1984 08:26", "DD/MM/YYYY HH:mm");
		this.input.val(startDate.format("DD/MM/YYYY HH:mm") + " - " + endDate.format("DD/MM/YYYY HH:mm"));
		this.input.calentim({
			format: "DD/MM/YYYY HH:mm",
			inline: true
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.config.startDate.format()).toEqual(moment("24/05/1984 08:25", "DD/MM/YYYY HH:mm").format());
		expect(this.calentim.config.endDate.format()).toEqual(moment("25/05/1984 08:26", "DD/MM/YYYY HH:mm").format());
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
	it("should set the start date and end date via function", function () {
		var startDate = moment("24/05/1994 08:25", "DD/MM/YYYY HH:mm");
		var endDate = moment("25/05/1995 08:26", "DD/MM/YYYY HH:mm");
		var startDate2 = moment("13/03/1989 18:28", "DD/MM/YYYY HH:mm");
		var endDate2 = moment("25/04/1989 07:27", "DD/MM/YYYY HH:mm");
		this.input.val(startDate.format("DD/MM/YYYY HH:mm") + " - " + endDate.format("DD/MM/YYYY HH:mm"));
		this.input.calentim({
			format: "DD/MM/YYYY HH:mm",
			inline: true
		});
		this.calentim = this.input.data("calentim");
		this.calentim.setStart(startDate2);
		this.calentim.setEnd(endDate2);
		this.calentim.setDisplayDate(startDate2);
		expect(this.calentim.config.startDate.format()).toEqual(startDate2.format());
		expect(this.calentim.config.endDate.format()).toEqual(endDate2.format());
		expect(this.calentim.input.find(".calentim-calendar:first").data("month").toString()).toEqual(startDate2.month().toString());
		expect(this.calentim.input.find(".calentim-day[data-value='" + startDate2.clone().middleOfDay().unix() + "']").hasClass("calentim-start")).toEqual(true);
		expect(this.calentim.input.find(".calentim-day[data-value='" + endDate2.clone().middleOfDay().unix() + "']").hasClass("calentim-end")).toEqual(true);
		if (this.calentim.config.hourFormat !== 12) {
			expect(this.calentim.input.find(".calentim-timepicker-start .calentim-timepicker-hours .calentim-hour-selected").text()).toEqual(startDate2.hours().toString());
			expect(this.calentim.input.find(".calentim-timepicker-start .calentim-timepicker-minutes .calentim-minute-selected").text()).toEqual(startDate2.minutes().toString());
			expect(this.calentim.input.find(".calentim-timepicker-end .calentim-timepicker-hours .calentim-hour-selected").text()).toEqual(endDate2.hours().toString());
			expect(this.calentim.input.find(".calentim-timepicker-end .calentim-timepicker-minutes .calentim-minute-selected").text()).toEqual(endDate2.minutes().toString());
		} else {
			var starts = startDate2.format("hh mm a").split(" ");
			var ends = endDate2.format("hh mm a").split(" ");
			expect(this.calentim.input.find(".calentim-timepicker-start .calentim-timepicker-hours .calentim-hour-selected").text()).toEqual(starts[0]);
			expect(this.calentim.input.find(".calentim-timepicker-start .calentim-timepicker-minutes .calentim-minute-selected").text()).toEqual(starts[1]);
			expect(this.calentim.input.find(".calentim-timepicker-start .calentim-ampm-selected").text().toLowerCase()).toEqual(starts[2]);
			expect(this.calentim.input.find(".calentim-timepicker-end .calentim-timepicker-hours .calentim-hour-selected").text()).toEqual(ends[0]);
			expect(this.calentim.input.find(".calentim-timepicker-end .calentim-timepicker-minutes .calentim-minute-selected").text()).toEqual(ends[1]);
			expect(this.calentim.input.find(".calentim-timepicker-end .calentim-ampm-selected").text().toLowerCase()).toEqual(ends[2]);
		}
	});
	it("should read the start date and end date from div", function () {
		var startDate = moment("24/05/1984 08:25", "DD/MM/YYYY HH:mm");
		var endDate = moment("25/05/1984 08:26", "DD/MM/YYYY HH:mm");
		this.input.remove();
		this.input = $("<div class='calentim-test-input'></div>").appendTo("body");
		this.input.text(startDate.format("DD/MM/YYYY HH:mm") + " - " + endDate.format("DD/MM/YYYY HH:mm"));
		this.input.calentim({
			format: "DD/MM/YYYY HH:mm",
			inline: true
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.config.startDate.format()).toEqual(moment("24/05/1984 08:25", "DD/MM/YYYY HH:mm").format());
		expect(this.calentim.config.endDate.format()).toEqual(moment("25/05/1984 08:26", "DD/MM/YYYY HH:mm").format());
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
	it("should have the start date effective on singledate", function () {
		var startDate = moment("24/05/1984 08:25", "DD/MM/YYYY HH:mm");
		this.input.calentim({ startDate: startDate, singleDate: true, inline: true });
		this.calentim = this.input.data("calentim");
		expect(this.calentim.config.startDate.format()).toEqual(moment("24/05/1984 08:25", "DD/MM/YYYY HH:mm").format());
		expect(this.calentim.input.find(".calentim-calendar:first").data("month").toString()).toEqual(startDate.month().toString());
		expect(this.calentim.input.find(".calentim-day[data-value='" + startDate.clone().middleOfDay().unix() + "']").hasClass("calentim-start")).toEqual(true);
		if (this.calentim.config.hourFormat !== 12) {
			expect(this.calentim.input.find(".calentim-timepicker-start .calentim-timepicker-hours .calentim-hour-selected").text()).toEqual(startDate.hours().toString());
			expect(this.calentim.input.find(".calentim-timepicker-start .calentim-timepicker-minutes .calentim-minute-selected").text()).toEqual(startDate.minutes().toString());
		} else {
			var starts = startDate.format("hh mm a").split(" ");
			expect(this.calentim.input.find(".calentim-timepicker-start .calentim-timepicker-hours .calentim-hour-selected").text()).toEqual(starts[0]);
			expect(this.calentim.input.find(".calentim-timepicker-start .calentim-timepicker-minutes .calentim-minute-selected").text()).toEqual(starts[1]);
			expect(this.calentim.input.find(".calentim-timepicker-start .calentim-ampm-selected").text().toLowerCase()).toEqual(starts[2]);
		}
	});
	it("should read the start date effective on singledate", function () {
		var startDate = moment("24/05/1984 08:25", "DD/MM/YYYY HH:mm");
		this.input.val(startDate.format("DD/MM/YYYY HH:mm"));
		this.input.calentim({ format: "DD/MM/YYYY HH:mm", singleDate: true, inline: true });
		this.calentim = this.input.data("calentim");
		expect(this.calentim.config.startDate.format()).toEqual(moment("24/05/1984 08:25", "DD/MM/YYYY HH:mm").format());
		expect(this.calentim.input.find(".calentim-calendar:first").data("month").toString()).toEqual(startDate.month().toString());
		expect(this.calentim.input.find(".calentim-day[data-value='" + startDate.clone().middleOfDay().unix() + "']").hasClass("calentim-start")).toEqual(true);
		if (this.calentim.config.hourFormat !== 12) {
			expect(this.calentim.input.find(".calentim-timepicker-start .calentim-timepicker-hours .calentim-hour-selected").text()).toEqual(startDate.hours().toString());
			expect(this.calentim.input.find(".calentim-timepicker-start .calentim-timepicker-minutes .calentim-minute-selected").text()).toEqual(startDate.minutes().toString());
		} else {
			var starts = startDate.format("hh mm a").split(" ");
			expect(this.calentim.input.find(".calentim-timepicker-start .calentim-timepicker-hours .calentim-hour-selected").text()).toEqual(starts[0]);
			expect(this.calentim.input.find(".calentim-timepicker-start .calentim-timepicker-minutes .calentim-minute-selected").text()).toEqual(starts[1]);
			expect(this.calentim.input.find(".calentim-timepicker-start .calentim-ampm-selected").text().toLowerCase()).toEqual(starts[2]);
		}
	});
	it("should respect the mindate on start date", function () {
		this.input.calentim({
			startDate: moment("22/11/2017 12:00:00", "DD/MM/YYYY HH:mm:ss"),
			endDate: moment("28/11/2017 12:00:00", "DD/MM/YYYY HH:mm:ss"),
			minDate: moment("24/11/2017 12:00:00", "DD/MM/YYYY HH:mm:ss"),
			inline: true
		});
		this.calentim = this.input.data("calentim");
		this.input.click();
		expect(this.calentim.config.startDate.inspect()).toBe(this.calentim.config.minDate.inspect());
	});
	it("should respect the mindate on end date", function () {
		this.input.calentim({
			startDate: moment("20/11/2017 12:00:00", "DD/MM/YYYY HH:mm:ss"),
			endDate: moment("28/11/2017 12:00:00", "DD/MM/YYYY HH:mm:ss"),
			minDate: moment("30/11/2017 12:00:00", "DD/MM/YYYY HH:mm:ss"),
			inline: true
		});
		this.calentim = this.input.data("calentim");
		this.input.click();
		expect(this.calentim.config.startDate.inspect()).toBe(this.calentim.config.minDate.inspect());
		expect(this.calentim.config.endDate.inspect()).toBe(this.calentim.config.minDate.inspect());
	});
	it("should respect the maxdate on end date", function () {
		this.input.calentim({
			startDate: moment("22/11/2017 12:00:00", "DD/MM/YYYY HH:mm:ss"),
			endDate: moment("28/11/2017 12:00:00", "DD/MM/YYYY HH:mm:ss"),
			maxDate: moment("24/11/2017 12:00:00", "DD/MM/YYYY HH:mm:ss"),
			inline: true
		});
		this.calentim = this.input.data("calentim");
		this.input.click();
		expect(this.calentim.config.endDate.inspect()).toBe(this.calentim.config.maxDate.inspect());
	});
	it("should respect the maxdate on start date", function () {
		this.input.calentim({
			startDate: moment("22/11/2017 12:00:00", "DD/MM/YYYY HH:mm:ss"),
			endDate: moment("28/11/2017 12:00:00", "DD/MM/YYYY HH:mm:ss"),
			maxDate: moment("20/11/2017 12:00:00", "DD/MM/YYYY HH:mm:ss"),
			inline: true
		});
		this.calentim = this.input.data("calentim");
		this.input.click();
		expect(this.calentim.config.startDate.inspect()).toBe(this.calentim.config.maxDate.inspect());
		expect(this.calentim.config.endDate.inspect()).toBe(this.calentim.config.maxDate.inspect());
	});
	it("should swap the start and end date", function () {
		var startDate = moment("20/11/2017 12:00:00", "DD/MM/YYYY HH:mm:ss");
		var endDate = moment("15/11/2017 12:00:00", "DD/MM/YYYY HH:mm:ss");
		this.input.calentim({
			startDate: startDate,
			endDate: endDate,
			inline: true
		});
		this.calentim = this.input.data("calentim");
		this.input.click();
		expect(this.calentim.config.startDate.inspect()).toBe(endDate.inspect());
		expect(this.calentim.config.endDate.inspect()).toBe(startDate.inspect());
	});
	it("should swap the min and max date", function () {
		var startDate = moment("20/11/2017 12:00:00", "DD/MM/YYYY HH:mm:ss");
		var endDate = moment("15/11/2017 12:00:00", "DD/MM/YYYY HH:mm:ss");
		this.input.calentim({
			minDate: startDate,
			maxDate: endDate,
			inline: true
		});
		this.calentim = this.input.data("calentim");
		this.input.click();
		expect(this.calentim.config.minDate.inspect()).toBe(endDate.inspect());
		expect(this.calentim.config.maxDate.inspect()).toBe(startDate.inspect());
	});
	it("should ignore invalid start and end date", function () {
		this.input.val("testing");
		var startDate = moment.invalid();
		var endDate = moment.invalid();
		this.input.calentim({
			startDate: startDate,
			endDate: endDate,
			inline: true
		});
		this.calentim = this.input.data("calentim");
		this.input.click();
		expect(this.calentim.config.startDate.isSame(moment(), "minute")).toBe(true);
		expect(this.calentim.config.endDate.isSame(moment(), "minute")).toBe(true);
	});
	it("should ignore invalid start date on single input", function () {
		this.input.val("testing");
		var startDate = moment.invalid();
		this.input.calentim({
			startDate: startDate,
			singleDate: true,
			inline: true
		});
		this.calentim = this.input.data("calentim");
		this.input.click();
		expect(this.calentim.config.startDate.isSame(moment(), "minute")).toBe(true);
	});
	it("should hide the calendar header", function () {
		this.input.calentim({
			showHeader: false,
			inline: true
		});
		this.calentim = this.input.data("calentim");

		expect($(".calentim-input").is(":visible")).toBe(true);
		expect(this.calentim.input.find(".calentim-header").length).toBe(1);
		expect(this.calentim.input.find(".calentim-header").is(":visible")).toBe(false);
	});
	it("should hide the calendars", function () {
		this.input.calentim({
			showCalendars: false,
			inline: true
		});
		this.calentim = this.input.data("calentim");

		expect($(".calentim-input").is(":visible")).toBe(true);
		expect(this.calentim.input.find(".calentim-calendars").length).toBe(1);
		expect(this.calentim.container.hasClass("calentim-hidden-calendar")).toBe(true);
		expect(this.calentim.input.find(".calentim-calendars").is(":visible")).toBe(false);
	});
	it("should hide the timepickers", function () {
		this.input.calentim({
			showTimePickers: false,
			inline: true
		});
		this.calentim = this.input.data("calentim");
		expect($(".calentim-input").is(":visible")).toBe(true);
		expect(this.calentim.input.find(".calentim-timepickers").length).toBe(1);
		expect(this.calentim.input.find(".calentim-timepickers").is(":visible")).toBe(false);
	});
	it("should not add the calendar footer", function () {
		this.input.calentim({
			showFooter: false,
			inline: true
		});
		this.calentim = this.input.data("calentim");

		expect($(".calentim-input").is(":visible")).toBe(true);
		expect(this.calentim.input.find(".calentim-footer").length).toBe(0);
	});
	it("should have 12 hour format", function () {
		this.input.calentim({
			hourFormat: 12,
			inline: true
		});
		this.calentim = this.input.data("calentim");

		expect($(".calentim-input").is(":visible")).toBe(true);
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
			hourFormat: 24,
			inline: true
		});
		this.calentim = this.input.data("calentim");

		expect($(".calentim-input").is(":visible")).toBe(true);
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
			minuteSteps: 15,
			inline: true
		});
		this.calentim = this.input.data("calentim");

		expect($(".calentim-input").is(":visible")).toBe(true);
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
			startOnMonday: true,
			inline: true
		});
		this.calentim = this.input.data("calentim");

		expect($(".calentim-input").is(":visible")).toBe(true);
		var calendarStart = this.calentim.input.find(".calentim-calendar:first").find(".calentim-disabled, .calentim-day").first().attr("data-value");
		var calendarStartMoment = moment.unix(calendarStart);
		expect(calendarStartMoment.isoWeekday()).toBe(1);
	});
	it("should start on Sunday", function () {
		this.input.calentim({
			startOnMonday: false,
			locale: 'tr',
			inline: true
		});
		this.calentim = this.input.data("calentim");

		expect($(".calentim-input").is(":visible")).toBe(true);
		var calendarStart = this.calentim.input.find(".calentim-calendar:first").find(".calentim-disabled, .calentim-day").first().attr("data-value");
		var calendarStartMoment = moment.unix(calendarStart);
		expect(calendarStartMoment.isoWeekday()).toBe(7);
	});
	it("should start empty", function () {
		this.input.calentim({
			startEmpty: true,
			inline: true
		});
		this.calentim = this.input.data("calentim");

		expect($(".calentim-input").is(":visible")).toBe(true);
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
	it("should open the month selector and select a month", function () {
		this.input.calentim({
			inline: true
		});
		this.calentim = this.input.data("calentim");
		this.calentim.input.find(".calentim-month-switch").click();
		expect(this.calentim.input.find(".calentim-month-selector").length).toEqual(1);
		expect(this.calentim.input.find(".calentim-month-selector").css("display")).toEqual("block");
		expect(this.calentim.input.find(".calentim-month-selector .calentim-ms-month:visible").length).toEqual(12);
		expect(this.calentim.input.find(".calentim-month-selector .calentim-ms-month.current").length).toEqual(1);
		this.calentim.input.find(".calentim-month-selector .calentim-ms-month:eq(3)").click();
		expect(this.calentim.input.find(".calentim-month-selector").length).toEqual(0);
		expect(this.calentim.input.find(".calentim-calendars .calentim-calendar:first").data("month")).toEqual(3);
	});
	it("shouldn't open the month selector when monthselector is disabled", function () {
		this.input.calentim({
			inline: true,
			enableMonthSwitcher: false
		});
		this.calentim = this.input.data("calentim");
		expect(this.input.find(".calentim-month-selector").length).toEqual(0);
	});
	it("should open the year selector and select a year", function () {
		this.input.calentim({
			inline: true
		});
		this.calentim = this.input.data("calentim");
		this.calentim.input.find(".calentim-year-switch").click();
		expect(this.calentim.input.find(".calentim-year-selector").length).toEqual(1);
		expect(this.calentim.input.find(".calentim-year-selector").css("display")).toEqual("block");
		expect(this.calentim.input.find(".calentim-year-selector .calentim-ys-year:visible").length).toEqual(13); // 5 x 3 - 2 arrows
		expect(this.calentim.input.find(".calentim-year-selector .calentim-ys-year.current").length).toEqual(1);
		var year = this.calentim.input.find(".calentim-year-selector .calentim-ys-year:eq(3)");
		var yearNumber = year.data("year");
		year.click();
		expect(this.calentim.input.find(".calentim-year-selector").length).toEqual(0);
		expect(this.calentim.globals.currentDate.year()).toEqual(yearNumber);
	});
	it("shouldn't open the year selector when year selector is disabled", function () {
		this.input.calentim({
			inline: true,
			enableYearSwitcher: false
		});
		this.calentim = this.input.data("calentim");
		expect(this.input.find(".calentim-year-selector").length).toEqual(0);
	});
	it("should open the year selector and change the pages backwards", function () {
		this.input.calentim({
			inline: true
		});
		this.calentim = this.input.data("calentim");
		this.calentim.input.find(".calentim-year-switch").click();
		expect(this.calentim.input.find(".calentim-year-selector").length).toEqual(1);
		expect(this.calentim.input.find(".calentim-year-selector").css("display")).toEqual("block");
		expect(this.calentim.input.find(".calentim-year-selector .calentim-ys-year:visible").length).toEqual(13); // 5 x 3 - 2 arrows
		expect(this.calentim.input.find(".calentim-year-selector .calentim-ys-year.current").length).toEqual(1);
		var year = this.calentim.input.find(".calentim-year-selector .calentim-ys-year:first");
		var yearNumber = year.data("year");
		for(var i = 0; i < 5; i++){
			this.calentim.input.find(".calentim-year-selector .calentim-ys-year-prev").click();
			expect(this.calentim.input.find(".calentim-year-selector").length).toEqual(1);
			year = this.calentim.input.find(".calentim-year-selector .calentim-ys-year:first");
			expect(yearNumber).toEqual(+year.data("year") + 13);
			yearNumber = year.data("year");
		}
	});
	it("should open the year selector and change the pages forward", function () {
		this.input.calentim({
			inline: true
		});
		this.calentim = this.input.data("calentim");
		this.calentim.input.find(".calentim-year-switch").click();
		expect(this.calentim.input.find(".calentim-year-selector").length).toEqual(1);
		expect(this.calentim.input.find(".calentim-year-selector").css("display")).toEqual("block");
		expect(this.calentim.input.find(".calentim-year-selector .calentim-ys-year:visible").length).toEqual(13); // 5 x 3 - 2 arrows
		expect(this.calentim.input.find(".calentim-year-selector .calentim-ys-year.current").length).toEqual(1);
		var year = this.calentim.input.find(".calentim-year-selector .calentim-ys-year:first");
		var yearNumber = year.data("year");
		for(var i = 0; i < 5; i++){
			this.calentim.input.find(".calentim-year-selector .calentim-ys-year-next").click();
			expect(this.calentim.input.find(".calentim-year-selector").length).toEqual(1);
			year = this.calentim.input.find(".calentim-year-selector .calentim-ys-year:first");
			expect(yearNumber).toEqual(+year.data("year") - 13);
			yearNumber = year.data("year");
		}
	});
	it("should apply the clicked range", function(){
		this.input.calentim({
			inline: true
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.config.ranges.length > 0).toEqual(true);
		this.calentim.input.find(".calentim-range:eq(1)").click();
		expect(this.calentim.input.find(".calentim-day[data-value='" + this.calentim.config.ranges[1].startDate.middleOfDay().unix() + "']").hasClass("calentim-start")).toBe(true);
		expect(this.calentim.input.find(".calentim-day[data-value='" + this.calentim.config.ranges[1].endDate.middleOfDay().unix() + "']").hasClass("calentim-end")).toBe(true);
	});
	it("should close when selection occurs (autocloseonselect)", function () {
		this.input.calentim({
			autoCloseOnSelect: true,
			inline: true
		});
		this.calentim = this.input.data("calentim");

		expect($(".calentim-input").is(":visible")).toBe(true);
		this.calentim.calendars.find(".calentim-calendar:first .calentim-day:first").click();
		this.calentim.calendars.find(".calentim-calendar:first .calentim-day:last").click();
		expect(this.input.val()).not.toBe("");
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

	});
	it("should disable days by function", function () {
		this.input.calentim({
			disableDays: function (day) {
				return day.isSame(moment(), "day");
			},
			inline: true
		});
		this.calentim = this.input.data("calentim");

		expect($(".calentim-input").is(":visible")).toBe(true);
		expect(this.calentim.calendars.find("[data-value='" + moment().middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
		expect(this.calentim.input.is(":visible")).toBe(true);
	});
	it("should disable days by array", function () {
		this.input.calentim({
			disabledRanges: [{
				start: moment().add(1, "day"),
				end: moment().add(3, "day")
			}],
			inline: true
		});
		this.calentim = this.input.data("calentim");

		expect($(".calentim-input").is(":visible")).toBe(true);
		expect(this.calentim.calendars.find("[data-value='" + moment().add(1, "day").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
		expect(this.calentim.calendars.find("[data-value='" + moment().add(2, "day").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
		expect(this.calentim.calendars.find("[data-value='" + moment().add(3, "day").middleOfDay().unix() + "']").hasClass("calentim-disabled")).toBe(true);
		expect(this.calentim.input.is(":visible")).toBe(true);
	});
	it("should preserve continuousity when disabling days by function", function () {
		this.input.calentim({
			disableDays: function (day) {
				return day.isSame(moment(), "day");
			},
			continuous: true,
			inline: true
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.is(":visible")).toBe(true);
		this.calentim.calendars.find("[data-value='" + moment().middleOfDay().add(-1, "day").unix() + "']").first().click();
		this.calentim.calendars.find("[data-value='" + moment().middleOfDay().add(-2, "day").unix() + "']").first().click();
		expect(this.calentim.config.startDate.isSame(moment().middleOfDay().add(-2, "day"), "day")).toBe(true);
		expect(this.calentim.config.endDate.isSame(moment().middleOfDay().add(-1, "day"), "day")).toBe(true);
		this.calentim.calendars.find("[data-value='" + moment().middleOfDay().add(-1, "day").unix() + "']").first().click();
		this.calentim.calendars.find("[data-value='" + moment().middleOfDay().add(1, "day").unix() + "']").first().click();
		expect(this.calentim.config.startDate.isSame(moment().middleOfDay().add(-2, "day"), "day")).toBe(true);
		expect(this.calentim.config.endDate.isSame(moment().middleOfDay().add(-1, "day"), "day")).toBe(true);
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
			inline: true,
			continuous: true
		});
		this.calentim = this.input.data("calentim");

		expect($(".calentim-input").is(":visible")).toBe(true);
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(-1, "day").unix() + "']").first().click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(-2, "day").unix() + "']").first().click();
		expect(this.calentim.config.startDate.isSame(moment({ day: dayStart }).middleOfDay().add(-2, "day"), "day")).toBe(true);
		expect(this.calentim.config.endDate.isSame(moment({ day: dayStart }).middleOfDay().add(-1, "day"), "day")).toBe(true);
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(-1, "day").unix() + "']").first().click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(3, "day").unix() + "']").first().click();
		expect(this.calentim.config.startDate.isSame(moment({ day: dayStart }).middleOfDay().add(-2, "day"), "day")).toBe(true);
		expect(this.calentim.config.endDate.isSame(moment({ day: dayStart }).middleOfDay().add(-1, "day"), "day")).toBe(true);
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
			continuous: true,
			startEmpty: true,
			inline: true
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.is(":visible")).toBe(true);
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
			continuous: true,
			startEmpty: true,
			inline: true
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.is(":visible")).toBe(true);
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(-1, "day").unix() + "']").first().click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(3, "day").unix() + "']").first().click();
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
			continuous: true,
			inline: true
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.is(":visible")).toBe(true);
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
			continuous: true,
			inline: true
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.is(":visible")).toBe(true);
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(-1, "day").unix() + "']").first().click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(1, "day").unix() + "']").first().click();
		expect(this.calentim.config.startDate.format("L")).toBe(moment().format("L"));
		expect(this.calentim.config.endDate.format("L")).toBe(moment().format("L"));
		expect(this.calentim.input.is(":visible")).toBe(true);
	});
	it("shouldn't show the buttons on inline", function () {
		var dayStart = 15;
		if(moment().date() == 14 || moment().date() == 15 || moment().date() == 16){
			dayStart = 22;
		}
		this.input.calentim({
			showButtons: true,
			inline: true
		});
		this.calentim = this.input.data("calentim");
		expect(this.calentim.input.is(":visible")).toBe(true);
		expect(this.calentim.config.startDate.format("L LT")).toBe(moment().format("L LT"));
		expect(this.calentim.config.endDate.format("L LT")).toBe(moment().format("L LT"));
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(-1, "day").unix() + "']").first().click();
		this.calentim.calendars.find("[data-value='" + moment({ day: dayStart }).middleOfDay().add(1, "day").unix() + "']").first().click();
		expect(this.calentim.config.startDate.format("L LT")).toBe(moment().date(dayStart-1).format("L LT"));
		expect(this.calentim.config.endDate.format("L LT")).toBe(moment().date(dayStart+1).format("L LT"));
		expect(this.calentim.input.find(".calentim-apply").length).toEqual(0);
		expect(this.calentim.$elem.val()).toBe(this.calentim.config.startDate.format("L LT") + this.calentim.config.dateSeparator + this.calentim.config.endDate.format("L LT"));
		expect(this.calentim.input.is(":visible")).toBe(true);
	})
});
