/* RainLoop Webmail (c) RainLoop Team | Licensed under AGPL 3 */
const gulp = require('gulp');

const concat = require('gulp-concat-util');
const rename = require('gulp-rename');
const replace = require('gulp-replace');
const eol = require('gulp-eol');
const livereload = require('gulp-livereload');
const filter = require('gulp-filter');
const expect = require('gulp-expect-file');

const { config } = require('./config');
const { del } = require('./common');

const cleanCss = require('gulp-clean-css');
const cssClean = () => del(config.paths.staticCSS + '/*.css');

const cssBootBuild = () => {
	const
		src = config.paths.css.boot.src;
	return gulp
		.src(src)
		.pipe(expect.real({ errorOnFailure: true }, src))
		.pipe(concat(config.paths.css.boot.name))
		.pipe(replace(/\.\.\/(img|images|fonts|svg)\//g, '$1/'))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS));
};

const cssMainBuild = () => {
	const
		less = require('gulp-less'),
		lessFilter = filter('**/*.less', { restore: true }),
		src = config.paths.css.main.src.concat([config.paths.less.main.src]);

	return gulp
		.src(src)
		.pipe(expect.real({ errorOnFailure: true }, src))
		.pipe(lessFilter)
		.pipe(
			less({
				'paths': config.paths.less.main.options.paths
			})
		)
		.pipe(lessFilter.restore)
		.pipe(concat(config.paths.css.main.name))
		.pipe(replace(/\.\.\/(img|images|fonts|svg)\//g, '$1/'))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS))
		.pipe(livereload());
};

const cssAdminBuild = () => {
	const
		less = require('gulp-less'),
		lessFilter = filter('**/*.less', { restore: true }),
		src = config.paths.css.main.src.concat([config.paths.less.admin.src]);

	return gulp
		.src(src)
		.pipe(expect.real({ errorOnFailure: true }, src))
		.pipe(lessFilter)
		.pipe(
			less({
				'paths': config.paths.less.main.options.paths
			})
		)
		.pipe(lessFilter.restore)
		.pipe(concat(config.paths.css.admin.name))
		.pipe(replace(/\.\.\/(img|images|fonts|svg)\//g, '$1/'))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS))
		.pipe(livereload());
};

const cssBootMin = () => {
	return gulp
		.src(config.paths.staticCSS + config.paths.css.boot.name)
		.pipe(cleanCss())
		.pipe(rename({ suffix: '.min' }))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS));
};

const cssMainMin = () => {
	return gulp
		.src(config.paths.staticCSS + config.paths.css.main.name)
		.pipe(cleanCss())
		.pipe(rename({ suffix: '.min' }))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS));
};

const cssAdminMin = () => {
	return gulp
		.src(config.paths.staticCSS + config.paths.css.admin.name)
		.pipe(cleanCss())
		.pipe(rename({ suffix: '.min' }))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS));
};

const cssBuild = gulp.parallel(cssBootBuild, cssMainBuild, cssAdminBuild);
const cssMin = gulp.parallel(cssBootMin, cssMainMin, cssAdminMin);

const cssLint = (done) => done();

const cssState1 = gulp.series(cssLint);
const cssState2 = gulp.series(cssClean, cssBuild, cssMin);

exports.cssLint = cssLint;
exports.cssBuild = cssBuild;

exports.css = gulp.parallel(cssState1, cssState2);
