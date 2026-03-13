'use strict';

const gulp        = require('gulp');
const gulpSass    = require('gulp-sass');
const dartSass    = require('sass');
const sass        = gulpSass(dartSass);
const postcss     = require('gulp-postcss');
const autoprefixer = require('autoprefixer');
const cssnano     = require('cssnano');
const terser      = require('gulp-terser');
const sourcemaps  = require('gulp-sourcemaps');

const paths = {
  scss: {
    src:  'assets/src/scf-style.scss',
    dest: 'assets/',
  },
  js: {
    src:  'assets/src/scf-filter.js',
    dest: 'assets/',
  },
  images: {
    src:  'assets/images/**/*.{jpg,jpeg,png,gif,svg}',
    dest: 'assets/images/',
  },
};

// Compile SCSS → CSS, autoprefix, minify
function styles() {
  return gulp
    .src(paths.scss.src)
    .pipe(sourcemaps.init())
    .pipe(sass.sync({ outputStyle: 'expanded', silenceDeprecations: ['legacy-js-api'] }).on('error', sass.logError))
    .pipe(postcss([
      autoprefixer(),
      cssnano({ preset: 'default' }),
    ]))
    .pipe(sourcemaps.write('.'))
    .pipe(gulp.dest(paths.scss.dest));
}

// Minify JS with Terser
function scripts() {
  return gulp
    .src(paths.js.src)
    .pipe(sourcemaps.init())
    .pipe(terser())
    .pipe(sourcemaps.write('.'))
    .pipe(gulp.dest(paths.js.dest));
}

// Optimise images (no-op if assets/images/ does not exist)
function images(done) {
  const fs = require('fs');
  if (!fs.existsSync('assets/images')) {
    done();
    return;
  }

  const imagemin = require('gulp-imagemin');
  return gulp
    .src(paths.images.src, { allowEmpty: true })
    .pipe(imagemin())
    .pipe(gulp.dest(paths.images.dest));
}

// Watch source files
function watch() {
  gulp.watch(paths.scss.src, styles);
  gulp.watch(paths.js.src,   scripts);
}

const build = gulp.series(styles, scripts, images);

exports.styles  = styles;
exports.scripts = scripts;
exports.images  = images;
exports.watch   = watch;
exports.build   = build;
exports.default = build;
