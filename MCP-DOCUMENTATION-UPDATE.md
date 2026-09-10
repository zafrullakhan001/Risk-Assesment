# MCP Documentation Update - Search Examples

## Summary

Updated the Risk Register MCP integration documentation with comprehensive, real-world search examples and use cases to help users understand how to query the SharePoint catalog through AI assistants.

## Files Updated

### 1. **public/includes/help-topics.php**
**Location in Help:** SharePoint → MCP integration for AI assistants

**Changes made:**
- ✅ Replaced basic 6-line example list with comprehensive categorized examples
- ✅ Added 10+ categories of search examples with natural language queries
- ✅ Included search operator reference with syntax examples
- ✅ Added file type, person, tags, and advanced operator examples
- ✅ Provided browsing, statistics, project details, and combining queries examples
- ✅ Added detailed operator reference table

**New sections added:**
- Basic Search (4 examples)
- Search by File Type (5 examples)
- Search by Person (4 examples)
- Search by Tags (5 examples)
- Advanced Search with Operators (5 examples)
- Browse and Explore (5 examples)
- Statistics and Overview (4 examples)
- Combining Multiple Queries (4 examples)
- Project Details (4 examples)
- Search operators reference (complete list with descriptions)

**Total examples added:** 40+ real-world query examples

---

### 2. **MCP-SEARCH-EXAMPLES.md** (NEW)
**Purpose:** Standalone comprehensive reference guide for users

**Contents:**
- 📖 Complete search example library organized by category
- 🎯 Real-world scenarios and use cases
- 👥 Role-based examples (Project Manager, Architect, Developer, Compliance)
- 💡 Tips for better searches
- 🔧 Troubleshooting common queries
- 📋 Search operator reference card
- 🚀 Quick start checklist

**Structure:**
1. What You Can Ask Your AI Assistant
2. Search by File Type (with supported extensions)
3. Search by Person (with format examples)
4. Search by Tags
5. Advanced Search with Operators (with table)
6. Browse and Explore
7. Statistics and Overview
8. Combining Multiple Queries
9. Project Details
10. Real-World Examples (6 scenarios)
11. Search Operator Reference Card
12. Pattern Examples
13. Use Cases by Role (4 roles)
14. Tips for Better Searches (5 tips)
15. Troubleshooting Common Queries
16. Getting Help
17. Quick Start Checklist

**Total content:** ~8 pages of examples, references, and guidance

---

## What Users Can Now Do

### Before This Update
Users saw a basic list:
```
"Search the SharePoint catalog for 'encore'"
"Find all projects with PDF files"
"What projects were modified by John Smith?"
"Show me details for Project Alpha"
"List all catalog sources"
"What are the most used tags?"
```

### After This Update
Users now have:

✅ **40+ conversational examples** showing exactly what to ask AI assistants  
✅ **10 categories** covering all search capabilities  
✅ **Complete operator reference** with syntax and descriptions  
✅ **File type support list** (PDF, DOCX, XLSX, PPTX, VSDX, ZIP, etc.)  
✅ **Real-world scenarios** (6 detailed examples)  
✅ **Role-based examples** (PM, Architect, Developer, Compliance)  
✅ **Search tips** and troubleshooting guidance  
✅ **Quick start checklist** for configuration and first queries  
✅ **Standalone reference document** (MCP-SEARCH-EXAMPLES.md)

---

## Example Categories Added

### 1. Basic Search
Natural language queries for projects, files, folders:
```
"Search for any project or file mentioning 'database migration'"
"Look for folders related to 'risk assessment'"
```

### 2. Search by File Type
Find documents by extension:
```
"Search for projects containing Visio diagrams"
"Show me projects with Excel spreadsheets (XLSX files)"
"Find projects that include PowerPoint presentations"
```

### 3. Search by Person
Filter by who worked on projects:
```
"What projects were modified by John Smith?"
"Find all folders created by Jane Doe"
"Show me projects last modified by the architecture team"
```

### 4. Search by Tags
Use project tags:
```
"Find projects tagged as 'priority'"
"Search for projects tagged 'infrastructure' or 'security'"
"What tags are available in the catalog?"
```

### 5. Advanced Operators
Combine filters:
```
"Find projects with 'network' but exclude 'legacy'"
"Search for exact phrase 'data center migration'"
"Find projects with extension:pdf AND tag:priority"
"Search for person:'Smith, John' AND ext:docx"
```

### 6. Browse and Explore
Discover available data:
```
"List all SharePoint catalog sources available"
"What's in the Public catalog versus Private catalog?"
"Get detailed information about Project Alpha including all files"
```

### 7. Statistics and Overview
Get insights:
```
"Give me statistics about the SharePoint catalog"
"How many projects are in each catalog source?"
"When was each catalog last synced?"
```

### 8. Combining Multiple Queries
Multi-step searches:
```
"Search for projects with PDF files, then show me the one modified most recently"
"Find all projects tagged 'active', then list those modified this month"
```

### 9. Project Details
Deep dive:
```
"Show me all files in the 'Customer Portal' project"
"What's the folder structure of 'Infrastructure Upgrade'?"
"List all documents in 'Architecture Review 2026' with their URLs"
```

### 10. Real-World Scenarios
Complete examples:
```
Scenario 1: "Search for projects with 'architecture' that have Visio diagrams and are tagged 'current'"
Scenario 2: "Find all projects modified by Sarah Johnson in the last 30 days"
Scenario 3: "List all projects that have PDF files but no Word documents"
```

---

## Search Operators Now Documented

| Operator | Example | Description |
|----------|---------|-------------|
| `tag:` | `tag:priority` | Filter by tag name |
| `ext:` | `ext:pdf` | Filter by file extension |
| `type:` | `type:visio` | Filter by file type |
| `person:` | `person:"Smith, John"` | Filter by person |
| `path:` | `path:drawings` | Filter by folder path |
| `has:` | `has:pdf` | Must contain file type |
| `"..."` | `"exact phrase"` | Exact text match |
| `-` | `-legacy` | Exclude term |
| AND | Implicit between terms | Combine filters |

---

## Role-Based Examples Added

### Project Manager
```
"Show me all projects tagged 'in-progress' with status updates in the last week"
"List projects by completion date"
"Find projects with incomplete deliverables"
```

### Architect
```
"Find all architecture decision records (ADR documents)"
"Search for design diagrams related to microservices"
"Show me architecture review documents from Q4 2026"
```

### Developer
```
"Find technical specifications for the API project"
"Search for code review documents"
"List projects with technical documentation"
```

### Compliance Officer
```
"Find all security assessment documents"
"Search for projects with audit reports"
"List projects that require compliance review"
```

---

## Tips for Better Searches (New Section)

1. **Start Broad, Then Narrow** - Progressive refinement strategy
2. **Use Exact Phrases** - Quote syntax for precision
3. **Combine Operators** - Multi-filter examples
4. **Explore Before Searching** - Discovery workflow
5. **Use Exclusions** - Negative filters to reduce noise

---

## Troubleshooting Section Added

### "No results found"
- Check spelling
- Try broader terms first
- Verify catalog is synced
- Remove some filters

### "Too many results"
- Add more specific operators
- Use exact phrases
- Filter by date/person/tag
- Search specific catalog source

### "Not finding expected project"
- Try partial name matching
- Search by person who worked on it
- Check different catalog sources
- Verify project isn't archived

---

## Access Points

Users can now find these examples in:

1. **In-App Help**
   - Navigate to: Help & About → SharePoint → MCP integration
   - Interactive help with font selection and read-aloud
   - Embedded directly in the application

2. **Standalone Guide**
   - File: `MCP-SEARCH-EXAMPLES.md`
   - Printable/shareable reference
   - Complete with all examples and troubleshooting

3. **Previous Documentation**
   - `MCP-GATEWAY-GUIDE.md` - Gateway setup (ngrok, Cloudflare, proxy)
   - `MCP-COMPLETE.md` - Technical implementation details

---

## User Benefits

✅ **Faster onboarding** - Clear examples show exactly what to ask  
✅ **Better search results** - Understand operators and syntax  
✅ **Role-specific guidance** - Examples tailored to job functions  
✅ **Troubleshooting help** - Solutions to common issues  
✅ **Multiple formats** - In-app help + standalone reference  
✅ **Natural language** - Conversational queries users can copy/paste  
✅ **Comprehensive coverage** - All MCP tools have example queries

---

## Configuration Examples Retained

The update preserved and enhanced existing configuration examples for:
- ✅ Cursor MCP settings
- ✅ GitHub Copilot configuration
- ✅ On-premise/Gateway setup (ngrok, Cloudflare Tunnel, corporate proxy)
- ✅ Security best practices
- ✅ Token management instructions

---

## Next Steps for Users

1. ✅ Visit Help & About → SharePoint → MCP integration
2. ✅ Read through example categories
3. ✅ Try basic searches first
4. ✅ Experiment with operators
5. ✅ Reference `MCP-SEARCH-EXAMPLES.md` for detailed guidance
6. ✅ Check troubleshooting if issues arise

---

## Metrics

- **Before:** 6 basic examples
- **After:** 40+ categorized examples + comprehensive reference guide
- **New documentation pages:** 1 (MCP-SEARCH-EXAMPLES.md)
- **Updated pages:** 1 (help-topics.php)
- **Total documentation:** ~10 pages of examples and guidance

---

**Last Updated:** September 10, 2026  
**Updated By:** AI Assistant  
**Requested By:** User (zafru)  
**Purpose:** Enhance MCP integration documentation with practical search examples
