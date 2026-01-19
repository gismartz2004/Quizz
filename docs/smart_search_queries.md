# Smart Search Query Examples

## Overview
The analytics dashboard supports natural language queries in the search bar. Type any of the following phrases to automatically filter and display the relevant data.

---

## 1. General Jornada Queries

**Matutina Data:**
- `datos matutina`
- `jornada matutina`
- `resultados matutina`

**Vespertina Data:**
- `datos vespertina`
- `jornada vespertina`
- `resultados vespertina`

---

## 2. Jornada by Subject Areas

**View subject performance by shift:**
- `datos matutina por áreas`
- `resultados vespertina por materias`
- `matutina por asignaturas`

---

## 3. Gender in Matutina

**Male students in matutina:**
- `datos hombres matutina`
- `resultados masculino matutina`
- `matutina hombres`

**Female students in matutina:**
- `datos mujeres matutina`
- `resultados femenino matutina`
- `matutina mujeres`

---

## 4. Gender in Vespertina

**Male students in vespertina:**
- `datos hombres vespertina`
- `resultados masculino vespertina`
- `vespertina hombres`

**Female students in vespertina:**
- `datos mujeres vespertina`
- `resultados femenino vespertina`
- `vespertina mujeres`

---

## 5. Parallel in Matutina

**View specific parallel data (A-D):**
- `datos paralelo A matutina`
- `resultados paralelo B matutina`
- `matutina paralelo C`

---

## 6. Parallel in Vespertina

**View specific parallel data (E-H):**
- `datos paralelo E vespertina`
- `resultados paralelo F vespertina`
- `vespertina paralelo G`

---

## 7. Parallel Performance Across Subjects

**View how a parallel performs in all subjects:**
- `datos paralelo A en las 4 asignaturas`
- `resultados paralelo B materias`
- `paralelo C asignaturas`

---

## 8. Parallels by Subjects Passed

**Filter by students who passed X subjects:**
- `datos paralelos aprobaron 3 materias`
- `paralelos con 2 materias aprobadas`
- `resultados paralelos aprobaron 4 materias`

---

## 9. Shifts by Subjects Passed

**Filter by jornada students who passed X subjects:**
- `datos jornadas aprobaron 3 materias`
- `jornadas con 2 materias aprobadas`
- `resultados jornadas aprobaron 4 materias`

---

## 10. Parallel in Matutina - Males

**Filter by male students of a specific parallel in matutina:**
- `datos paralelo A matutina hombres`
- `resultados paralelo B en matutina masculinos`
- `hombres paralelo C matutina`

---

## 11. Parallel in Matutina - Females

**Filter by female students of a specific parallel in matutina:**
- `datos paralelo A matutina mujeres`
- `resultados paralelo B en matutina femeninos`
- `mujeres paralelo C matutina`

---

## Additional Existing Patterns

### Gender Filters
- `mujeres` / `femenino` - Filter female students
- `hombres` / `masculino` - Filter male students

### Academic Performance
- `reprobados` - Students who failed (< 70)
- `aprobados` - Students who passed (≥ 70)
- `honor` / `excelencia` - Top students (≥ 90)

### Specific Parallel
- `paralelo A` - Filter by paralelo A
- `curso B` - Filter by paralelo B

### Specific Grades
- `nota 85` - Students with scores around 85 (±2)
- `mayor a 75` - Scores above 75
- `menor a 60` - Scores below 60

### Age
- `15 años` - Students aged 15
- `edad 14` - Students aged 14

### Integrity
- `copia` / `trampa` / `riesgo` - Students with integrity alerts

### Count Queries
- `cuantos estudiantes aprobaron 3 materias` - Count students who passed 3+ subjects

---

## Tips

1. **Combine filters:** You can use multiple query terms to refine your search
2. **Case insensitive:** Search is not case sensitive
3. **Flexible wording:** Many variations of the same query will work
4. **Console logs:** Check browser console to see which patterns were matched

---

## Technical Notes

- Patterns are processed in order
- Multiple matches can apply simultaneously
- Form fields are automatically populated based on detected patterns
- Console logs show which pattern IDs were triggered for debugging
