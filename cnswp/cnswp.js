document.addEventListener("DOMContentLoaded", function(event) {
    if (typeof cnswp !== 'undefined'){
        var skips = ['hero','button-group', 'pagination'];
        for (var i = 0; i < skips.length; i++){
            var x = document.getElementsByClassName(skips[i]);
            for (var j = 0; j < x.length;  j++) {
                x[j].style.display = "none";
            }
        }
    }
});

function MM_jumpMenu(targ,selObj,restore){ //v3.0
  eval(targ+".location='"+selObj.options[selObj.selectedIndex].value+"'");
  if (restore) selObj.selectedIndex=0;
}
