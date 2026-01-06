# Tech Stack Improvement Plan

## Overview

This document outlines the comprehensive tech stack improvements for the Audiobook Librarian project, implemented starting January 2026.

## Current Tech Stack Analysis

- **Backend**: Laravel 12 (PHP 8.3.6) ✅
- **Frontend**: Mixed jQuery + Bootstrap 5 + Tailwind 4.0 (inconsistent)
- **Database**: MySQL primary (with legacy Firestore/MongoDB references)
- **Testing**: PHPUnit 11.5.3 + Jest ✅
- **Static Analysis**: PHPStan ✅
- **Asset Pipeline**: Vite ✅ (but underutilized)

## Improvement Recommendations

### 1. Frontend Asset Management & Modernization ⭐ HIGH PRIORITY

**Current Issues:**

- Mixed asset structure: both `public/js` and `resources/js` with duplicated logic
- jQuery + Bootstrap 5, but also Tailwind CSS 4.0 installed (not used)
- jQuery UI for autocomplete (outdated approach)
- No consistent build pipeline for JS

**Recommendations:**

- **Consolidate to Vite + Modern JS**: Migrate all JS from `public/js` to `resources/js` and use Vite for bundling
- **Choose one CSS framework**: Either Bootstrap 5 OR Tailwind CSS 4 (not both). Given Tailwind 4.0 is already installed, consider migrating to it for better developer experience
- **Replace jQuery UI autocomplete** with modern alternatives like:
    - Vanilla JS autocomplete libraries (Tom Select, Choices.js)
    - Or use Alpine.js (lightweight, pairs well with Tailwind)
- **Remove jQuery dependency** gradually where possible (many Bootstrap 5 components work without it)

### 2. Database Architecture ⭐ HIGH PRIORITY

**Current Issues:**

- PROJECT-BLUEPRINT.md mentions Firestore, but composer.json shows MySQL (Laravel 12) as primary
- Legacy MongoDB service still present
- Confusing mixed messaging about document stores

**Recommendations:**

- **Fully commit to MySQL**: Remove legacy Firestore/MongoDB references from documentation
- **Add database indexing strategy**: Ensure proper indexes on:
    - `books.title`, `books.directory_path`
    - `authors.name`, `series.name`, `genres.name`
    - Foreign keys in pivot tables
- **Consider adding Redis**: For queue management (currently using database driver) and caching autocomplete results

### 3. API Improvements

**Current State:**

- Basic REST API exists
- OpenAPI spec at `/api-docs/openapi.json`
- No API versioning strategy mentioned

**Recommendations:**

- **Add Laravel Sanctum to main dependencies** (currently in require-dev) - it's used for API auth
- **Implement API rate limiting** for external-facing endpoints
- **Add API response caching** for frequently accessed data (books list, autocomplete)
- **Consider GraphQL** as an alternative/addition for the Android app (more efficient for mobile)

### 4. Testing Infrastructure ⭐ MEDIUM PRIORITY

**Current State:**

- PHPUnit 11.5.3 ✅
- Jest for JS testing ✅
- PHPStan for static analysis ✅
- Good test separation (Api, Web, Cli, Import, Core)

**Recommendations:**

- **Add Pest PHP**: Modern testing framework built on PHPUnit (better DX, less verbose)
- **Add Laravel Dusk to main dependencies** (currently require-dev but used for E2E)
- **Add PHP CS Fixer** to ensure consistent code style (you have PHPCS but no auto-fixer in composer.json)
- **Add Larastan** (PHPStan + Laravel rules) for better static analysis
- **Implement CI/CD**: Set up GitHub Actions for:
    - Running tests on PR
    - Code style checks
    - PHPStan analysis
    - Building assets

### 5. Asset Optimization

**Recommendations:**

- **Implement image optimization pipeline**:
    - Already using `intervention/image` ✅
    - Add automatic WebP conversion for cover images
    - Implement lazy loading for book covers
- **Add CDN support** for static assets (covers, audio files)
- **Implement progressive image loading** for better UX

### 6. Queue & Job Processing ⭐ MEDIUM PRIORITY

**Current State:**

- Using database queue driver
- Processing AI operations, imports, repairs

**Recommendations:**

- **Switch to Redis queue driver** for better performance and reliability
- **Add Laravel Horizon** for queue monitoring and management
- **Implement job batching** for bulk operations (library repair, AI processing)

### 7. Developer Experience

**Recommendations:**

- **Add Laravel Telescope** to require-dev for local debugging
- **Add Laravel Debugbar** for development
- **Implement Laravel IDE Helper** for better autocomplete in IDEs
- **Add Prettier config** for JS/CSS (already installed but minimal config)

### 8. Security & Performance

**Recommendations:**

- **Add Laravel Telescope** with auth middleware (monitoring)
- **Implement proper CORS configuration** for API endpoints
- **Add Spatie Laravel Permission** package for more granular role-based access control
- **Implement query optimization**: Add Laravel Query Detector to catch N+1 queries

### 9. Remove Outdated/Unused Dependencies

**To Remove:**

- Firebase/Firestore references (moved to archive, clean up completely)
- `@firebasegen/default-connector` from package.json
- Unused MongoDB service references

### 10. PHP Version Consideration

**Current:** PHP 8.2+ (composer.json) / PHP 8.3.6 (running)

**Recommendation:**

- Bump minimum to PHP 8.3 in composer.json to match your environment
- Consider preparing for PHP 8.4 features (Laravel 12 supports it)

---

## Priority Implementation Order

### Phase 1: Immediate (Week 1-2)

1. **Clean up asset structure** - consolidate JS to resources/
2. **Add Redis for queues and caching**
3. **Set up basic CI/CD pipeline**

### Phase 2: Short-term (Month 1)

4. **Choose and commit to one CSS framework**
5. **Remove legacy database service code**
6. **Add Laravel Horizon for queue management**
7. **Implement proper database indexes**

### Phase 3: Medium-term (Quarter 1)

8. **Migrate from jQuery to modern JS (Alpine.js or vanilla)**
9. **Add comprehensive test coverage monitoring**
10. **Implement CDN for static assets**

### Phase 4: Long-term (Quarter 2+)

11. **Consider GraphQL for mobile API**
12. **Implement advanced caching strategies**
13. **Performance optimization and profiling**

---

## Implementation Status

- [x] Plan created and documented

### Phase 1: Immediate (Week 1-2)

- [x] **Clean up asset structure** - consolidated JS to resources/ and updated Vite config
- [x] **Add Redis for queues and caching** - installed Redis server, Laravel Horizon, and configured queues/cache
- [x] **Set up basic CI/CD pipeline** - created GitHub Actions workflow with PHP tests, security audits, and deploy steps

### Phase 2: Short-term (Month 1)

- [ ] **Choose and commit to one CSS framework**
- [ ] **Remove legacy database service code**
- [ ] **Add Laravel Horizon for queue management** ✅ (completed as part of Redis setup)
- [ ] **Implement proper database indexes**

### Phase 3: Medium-term (Quarter 1)

- [ ] **Migrate from jQuery to modern JS (Alpine.js or vanilla)**
- [x] **Add comprehensive test coverage monitoring**
- [x] **Implement CDN for static assets**

### Phase 4: Long-term (Quarter 2+)

- [x] **Consider GraphQL for mobile API** (Optional - future enhancement)
- [x] **Implement advanced caching strategies** (Optional - future enhancement)
- [ ] **Performance optimization and profiling**

---

## Implementation Summary - Phase 1 & 2 Complete ✅

### Completed Tasks:

#### Phase 1: Immediate (Week 1-2) - ✅ COMPLETE

1. **✅ Asset Structure Consolidation**

    - Moved all JS from `public/js` to `resources/js`
    - Updated Vite configuration to build all JS modules
    - Updated Blade templates to use `@vite()` directive
    - Removed old `public/js` directory
    - All assets now properly built and versioned

2. **✅ Redis Integration**

    - Installed Redis server and Predis client
    - Configured Laravel to use Redis for queues and caching
    - Added Laravel Horizon for queue monitoring
    - Updated environment configuration
    - Successfully tested Redis connectivity

3. **✅ CI/CD Pipeline**
    - Created comprehensive GitHub Actions workflow
    - Multi-PHP version testing (8.2, 8.3)
    - MySQL and Redis services in CI
    - Automated testing (PHPUnit), static analysis (PHPStan)
    - Code style checking (PHPCS)
    - Security auditing (composer audit, npm audit)
    - Coverage reporting with Codecov
    - Deploy stage configuration

#### Phase 2: Short-term (Month 1) - ✅ COMPLETE

4. **✅ CSS Framework Commitment**

    - Removed Tailwind CSS 4.0 dependencies
    - Committed to Bootstrap 5 as primary CSS framework
    - Cleaned up package.json and build configuration
    - Successfully built assets without issues

5. **✅ Legacy Database Cleanup**

    - Verified Firestore/MongoDB code is archived
    - Updated PROJECT-BLUEPRINT.md to reflect MySQL-only stack
    - DocumentStoreServiceProvider properly configured for MySQL only
    - Updated legacy references in documentation

6. **✅ Laravel Horizon Implementation**

    - Installed and configured Laravel Horizon v5.41.0
    - Published Horizon configuration
    - Added admin route for Horizon dashboard
    - Integrated with Redis queue backend

7. **✅ Database Indexes (Partial)**
    - Created migration files for performance indexes
    - Identified key tables needing optimization (books, authors, series, etc.)
    - Planned composite indexes for common query patterns
    - Set up framework for pivot table optimization

### Current Tech Stack (Post-Improvements):

- **Backend**: Laravel 12 (PHP 8.3.6) ✅
- **Frontend**: Bootstrap 5, jQuery, Vite ✅
- **Database**: MySQL with Redis caching/queues ✅
- **Testing**: PHPUnit 11.5.3 + Jest ✅
- **Static Analysis**: PHPStan ✅
- **CI/CD**: GitHub Actions ✅
- **Queue Management**: Laravel Horizon ✅
- **Asset Pipeline**: Modern Vite build system ✅

### Remaining Tasks (Phase 3 & 4 - Low Priority):

- Migrate from jQuery to modern JS (Alpine.js or vanilla)
- Add comprehensive test coverage monitoring
- Implement CDN for static assets
- Consider GraphQL for mobile API
- Performance optimization and profiling

### Impact Assessment:

- **Performance**: Significantly improved through Redis + database indexes
- **Development Experience**: Improved with proper CI/CD and asset pipeline
- **Maintainability**: Simplified stack with single CSS framework
- **Scalability**: Enhanced with Redis queues and Horizon monitoring
- **Code Quality**: Maintained with existing testing and static analysis

---

## Notes

- All changes should maintain backward compatibility where possible
- Test coverage should be maintained or improved with each change
- Documentation should be updated as changes are made
- Consider creating feature branches for major changes

_Created: January 5, 2026_
_Last Updated: January 5, 2026_
