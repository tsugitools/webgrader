describe("JavaScript behavior", function () {

    // WebGrader test: add-two
    // WebGrader points: 5
    it("add_two returns the sum", function () {
        expect(typeof window["add_two"]).toBe("function");
        expect(window["add_two"](2, 3)).toBe(5);
        expect(window["add_two"](4, 5)).toBe(9);
        expect(window["add_two"](7, 2)).toBe(9);
    });

    // WebGrader test: square
    // WebGrader points: 5
    it("square returns n * n", function () {
        expect(typeof window["square"]).toBe("function");
        expect(window["square"](2)).toBe(4);
        expect(window["square"](4)).toBe(16);
        expect(window["square"](7)).toBe(49);
    });
});
