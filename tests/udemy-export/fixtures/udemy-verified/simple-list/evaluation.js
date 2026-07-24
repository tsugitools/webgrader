describe("HTML structure", function () {

    // WebGrader test: has-ul
    // WebGrader points: 2
    it("Has an unordered list", function () {
        expect(document.querySelector("ul")).not.toBeNull();
    });

    // WebGrader test: three-items
    // WebGrader points: 4
    it("List has three items", function () {
        expect(document.querySelectorAll("ul li").length).toBe(3);
    });

    // WebGrader test: first-apples
    // WebGrader points: 3
    it("First item is Apples", function () {
        var el = document.querySelector("ul li:first-child");
        expect(el).not.toBeNull();
        var actual = (el.textContent || "").replace(/\s+/g, " ").trim();
        expect(actual).toBe("Apples");
    });

    // WebGrader test: no-ol
    // WebGrader points: 1
    it("No ordered list", function () {
        expect(document.querySelector("ol")).toBeNull();
    });
});
