// mini/form.js - http://timmorgan.org/mini

function $(e){if(typeof e=='string')e=document.getElementById(e);return e};
function collect(a,f){var n=[];for(var i=0;i<a.length;i++){var v=f(a[i]);if(v!=null)n.push(v)}return n};

form={};
form.errmsg='Please fill in all required fields.'
form.errclass='error'
form.noerrclass=''
form.validate=function(frm){var g=function(n){var a=[];var e=frm.getElementsByTagName(n);for(var i=0;i<e.length;i++)a.push(e[i]);return a};var f=g('input').concat(g('select')).concat(g('textarea'));if(collect(f,function(i){var l=form.label(i);if(i.getAttribute('required')&&i.value.replace(' ','')==''){if(l)l.className=form.errclass;return i}else if(l)l.className=form.noerrclass}).length>0){alert(form.errmsg);return false}else return true};
form.label=function(elm){var l=collect(document.getElementsByTagName('label'),function(i){var f=i.getAttribute('for')||i.getAttribute('htmlFor');if($(f)==$(elm))return i});if(l.length>0)return l[0]};

function y2k(number) { return (number < 1000) ? number + 1900 : number; }
function check_date (myDateObj) {
// checks if date passed is in valid mm-dd-yyyy format
	myDate = myDateObj.value;

	if (myDate.length == 10) {
		if (myDate.substring(2,3) == '-' && myDate.substring(5,6) == '-') {
			var month  = myDate.substring(0,2);
			var date = myDate.substring(3,5);
			var year  = myDate.substring(6,10);
			var test = new Date(year,month-1,date);

			if (year == y2k(test.getYear()) && (month-1 == test.getMonth()) && (date == test.getDate())) return true;
			else return date_error(myDateObj, 'Invalid date: date does not exist!');
		}
		else return date_error(myDateObj, 'Invalid date: expected format mm-dd-yyyy');
	}
	else return date_error(myDateObj, 'Invalid date: expected format mm-dd-yyyy');
}
function date_error(myDateObj, text) {alert(text);myDateObj.value = '';return false;}

function check_time(myTimeObj) {
	// Checks time format is hh:mm
	myTime = myTimeObj.value;
	
	if (myTime.length == 5) {
		if (myTime.substring(2,3) == ':') {
			var hour = myTime.substring(0,2);
			var min = myTime.substring(3,5);
			
			if (hour > -1 && hour < 24) {
				if (min > -1 && min < 60) {
					return true;
				} else
					return time_error(myTimeObj, 'Invalid time: expected format hh:mm');
			} else
				return time_error(myTimeObj, 'Invalid time: expected format hh:mm');
		} else
			return time_error(myTimeObj, 'Invalid time: expected format hh:mm');
	} else
		return time_error(myTimeObj, 'Invalid time: expected format hh:mm');
}
function time_error(myTimeObj, text) {alert(text);myTimeObj.value = ''; return false;}