// WebGrader Udemy export helpers for computed-style comparisons
function wgIsColorProperty(prop) {
    var p = String(prop || "").toLowerCase();
    return p === "color" || p.indexOf("color") !== -1 || p === "fill" || p === "stroke";
}
function wgIsOffsetProperty(prop) {
    var p = String(prop || "").toLowerCase();
    return p === "top" || p === "right" || p === "bottom" || p === "left";
}
function wgNormalizeOffset(value) {
    var v = String(value || "").trim().toLowerCase();
    return v === "0" ? "0px" : v;
}
function wgNormalizeColor(value) {
    var raw = String(value || "").trim();
    if (!raw || !document.body) return raw;
    var probe = document.createElement("div");
    probe.style.backgroundColor = raw;
    document.body.appendChild(probe);
    var resolved = window.getComputedStyle(probe).backgroundColor;
    document.body.removeChild(probe);
    return (resolved || raw).trim();
}
function wgNormalizeComputed(prop, value) {
    var v = String(value || "").trim();
    if (wgIsColorProperty(prop)) return wgNormalizeColor(v);
    if (wgIsOffsetProperty(prop)) return wgNormalizeOffset(v);
    return v;
}

describe("CSS presentation", function () {

    // WebGrader test: title-bg
    // WebGrader points: 2
    it("#title background is yellow", function () {
        var el = document.querySelector("#title");
        expect(el).not.toBeNull();
        var prop = "background-color";
        var actual = window.getComputedStyle(el).getPropertyValue(prop).trim();
        var expected = "yellow";
        expect(wgNormalizeComputed(prop, actual)).toBe(wgNormalizeComputed(prop, expected));
    });

    // WebGrader test: intro-bg
    // WebGrader points: 2
    it(".intro background is lightblue", function () {
        var el = document.querySelector(".intro");
        expect(el).not.toBeNull();
        var prop = "background-color";
        var actual = window.getComputedStyle(el).getPropertyValue(prop).trim();
        var expected = "lightblue";
        expect(wgNormalizeComputed(prop, actual)).toBe(wgNormalizeComputed(prop, expected));
    });

    // WebGrader test: main-bg
    // WebGrader points: 2
    it("#main background is orange", function () {
        var el = document.querySelector("#main");
        expect(el).not.toBeNull();
        var prop = "background-color";
        var actual = window.getComputedStyle(el).getPropertyValue(prop).trim();
        var expected = "orange";
        expect(wgNormalizeComputed(prop, actual)).toBe(wgNormalizeComputed(prop, expected));
    });

    // WebGrader test: footer-bg
    // WebGrader points: 2
    it(".footer background is pink", function () {
        var el = document.querySelector(".footer");
        expect(el).not.toBeNull();
        var prop = "background-color";
        var actual = window.getComputedStyle(el).getPropertyValue(prop).trim();
        var expected = "pink";
        expect(wgNormalizeComputed(prop, actual)).toBe(wgNormalizeComputed(prop, expected));
    });
});
